<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryField;
use App\Support\Connectors\ConnectorDiscoveryIdentifiedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use App\Support\Connectors\ConnectorSchemaFieldV2Hasher;
use App\Support\Connectors\ConnectorSchemaSnapshotV2Hasher;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use Carbon\CarbonImmutable;

final class AdobePaaSDiscoveryCapabilityImpl implements AdobePaaSDiscoveryCapability
{
    private const int PAGE_SIZE = 200;

    private const int MAX_PAGES = 50;

    private const int MAX_FIELDS = 10_000;

    public function __construct(
        private readonly AdobePaaSDiscoveryRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobePaaSDiscoveryResponseMapper $responseMapper,
        private readonly AdobePaaSDiscoveryTransportMapper $transportMapper,
        private readonly AdobePaaSAttributeNormalizer $normalizer,
        private readonly AdobePaaSServiceOnlyAttributeEligibility $serviceOnlyAttributeEligibility,
        private readonly AdobePaaSAttributeIdentityExtractor $identityExtractor = new AdobePaaSAttributeIdentityExtractor,
        private readonly AdobePaaSAttributePayloadProjector $payloadProjector = new AdobePaaSAttributePayloadProjector,
        private readonly ConnectorSchemaFieldV2Hasher $fieldHasher = new ConnectorSchemaFieldV2Hasher,
        private readonly ConnectorSchemaSnapshotV2Hasher $snapshotHasher = new ConnectorSchemaSnapshotV2Hasher,
    ) {}

    public function discover(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
    ): ConnectorDiscoveryAttemptResult {
        /** @var list<ConnectorDiscoveryField> $accumulatedFields */
        $accumulatedFields = [];
        /** @var array<string, true> $seenFieldKeys */
        $seenFieldKeys = [];
        $receivedItemsCount = 0;
        $stableTotalCount = null;
        $currentPage = 1;

        while ($currentPage <= self::MAX_PAGES) {
            $signingContext = new OAuth1SigningContext(bin2hex(random_bytes(16)), time());
            $request = $this->requestFactory->build($context, $endpointPath, $currentPage, $signingContext);
            $outboundRequest = new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 60.0,
                    maxResponseBodyBytes: 2 * 1024 * 1024,
                ),
            );

            try {
                $httpResult = $this->transport->send($outboundRequest);
            } catch (ConnectorTransportException $exception) {
                return $this->transportMapper->map($exception);
            }

            $pageResult = $this->responseMapper->map($httpResult);
            if ($pageResult->failure !== null) {
                return $pageResult->failure;
            }
            $page = $pageResult->page;

            if ($page->totalCount > self::MAX_FIELDS) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded);
            }
            if ($stableTotalCount === null) {
                $stableTotalCount = $page->totalCount;
            } elseif ($page->totalCount !== $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination);
            }

            foreach ($page->items as $itemIndex => $rawItem) {
                $receivedItemsCount++;

                try {
                    $fieldKey = $this->identityExtractor->extract($rawItem);
                } catch (ConnectorDiscoverySchemaValidationException) {
                    return ConnectorDiscoveryAttemptResult::schemaValidationFailure();
                }

                if (isset($seenFieldKeys[$fieldKey])) {
                    return ConnectorDiscoveryAttemptResult::schemaValidationFailure();
                }
                $seenFieldKeys[$fieldKey] = true;

                /** @var \stdClass $rawItem */
                if ($this->serviceOnlyAttributeEligibility->shouldSkip($rawItem)) {
                    $identified = ConnectorDiscoveryIdentifiedField::unclassified(
                        $fieldKey,
                        $this->bestEffortLabel($rawItem),
                        $this->payloadProjector->projectBestEffort($rawItem),
                        null,
                    );
                } else {
                    try {
                        $identified = ConnectorDiscoveryIdentifiedField::normalized(
                            $this->normalizer->normalizeIdentifiedV2($rawItem, $fieldKey),
                        );
                    } catch (ConnectorDiscoverySchemaValidationException $exception) {
                        $identified = ConnectorDiscoveryIdentifiedField::unclassified(
                            $fieldKey,
                            $this->bestEffortLabel($rawItem),
                            $this->payloadProjector->projectBestEffort($rawItem),
                            $exception->reason,
                        );
                    }
                }

                $accumulatedFields[] = new ConnectorDiscoveryField(
                    $identified,
                    $this->fieldHasher->hash($identified),
                );
            }

            if ($receivedItemsCount === $stableTotalCount) {
                break;
            }
            if ($currentPage === self::MAX_PAGES && $receivedItemsCount < $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded);
            }
            if ($page->items === [] && $receivedItemsCount < $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination);
            }
            if ($receivedItemsCount > $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination);
            }
            $currentPage++;
        }

        if ($stableTotalCount === null || $receivedItemsCount !== $stableTotalCount) {
            return ConnectorDiscoveryAttemptResult::paginationFailure(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination);
        }

        $fieldHashes = array_map(
            fn (ConnectorDiscoveryField $field): CanonicalSchemaFieldHash => CanonicalSchemaFieldHash::create(
                $field->field->externalFieldKey(),
                $field->canonicalHash,
            ),
            $accumulatedFields,
        );

        $candidate = ConnectorDiscoverySnapshotCandidate::create(
            $accumulatedFields,
            $this->snapshotHasher->hash($fieldHashes),
            CarbonImmutable::now(),
            $receivedItemsCount,
        );

        return ConnectorDiscoveryAttemptResult::success($candidate);
    }

    private function bestEffortLabel(#[\SensitiveParameter] \stdClass $raw): ?string
    {
        if (! property_exists($raw, 'default_frontend_label') || $raw->default_frontend_label === null) {
            return null;
        }
        if (! is_string($raw->default_frontend_label) || ! mb_check_encoding($raw->default_frontend_label, 'UTF-8')) {
            return null;
        }

        return $raw->default_frontend_label;
    }
}
