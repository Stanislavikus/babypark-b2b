<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
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
        private readonly CanonicalSchemaFieldHasher $fieldHasher,
        private readonly CanonicalSchemaSnapshotHasher $snapshotHasher,
    ) {}

    public function discover(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
    ): ConnectorDiscoveryAttemptResult {
        /** @var list<ConnectorDiscoveryNormalizedField> $accumulatedFields */
        $accumulatedFields = [];
        $stableTotalCount = null;
        $currentPage = 1;

        while ($currentPage <= self::MAX_PAGES) {
            $signingContext = new OAuth1SigningContext(
                bin2hex(random_bytes(16)),
                time(),
            );

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

            if ($stableTotalCount === null) {
                $stableTotalCount = $page->totalCount;
            } elseif ($page->totalCount !== $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(
                    ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
                );
            }

            if ($stableTotalCount > self::MAX_FIELDS) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(
                    ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded,
                );
            }

            if ($page->items === [] && count($accumulatedFields) < $stableTotalCount) {
                if ($currentPage < self::MAX_PAGES) {
                    return ConnectorDiscoveryAttemptResult::paginationFailure(
                        ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
                    );
                }

                return ConnectorDiscoveryAttemptResult::paginationFailure(
                    ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded,
                );
            }

            foreach ($page->items as $rawItem) {
                try {
                    $canonicalField = $this->normalizer->normalize($rawItem);
                } catch (ConnectorDiscoverySchemaValidationException) {
                    return ConnectorDiscoveryAttemptResult::schemaValidationFailure();
                }

                $accumulatedFields[] = new ConnectorDiscoveryNormalizedField(
                    $canonicalField,
                    $this->fieldHasher->hash($canonicalField),
                );
            }

            if (count($accumulatedFields) === $stableTotalCount) {
                break;
            }

            if ($currentPage === self::MAX_PAGES && count($accumulatedFields) < $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(
                    ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded,
                );
            }

            if (count($accumulatedFields) > $stableTotalCount) {
                return ConnectorDiscoveryAttemptResult::paginationFailure(
                    ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
                );
            }

            $currentPage++;
        }

        if ($stableTotalCount === null || count($accumulatedFields) !== $stableTotalCount) {
            return ConnectorDiscoveryAttemptResult::paginationFailure(
                ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
            );
        }

        $fieldHashes = array_map(
            fn (ConnectorDiscoveryNormalizedField $field): CanonicalSchemaFieldHash => CanonicalSchemaFieldHash::create(
                $field->field->externalFieldKey(),
                $field->canonicalHash,
            ),
            $accumulatedFields,
        );

        $snapshotHash = $this->snapshotHasher->hash($fieldHashes);

        $candidate = ConnectorDiscoverySnapshotCandidate::create(
            $accumulatedFields,
            $snapshotHash,
            CarbonImmutable::now(),
        );

        return ConnectorDiscoveryAttemptResult::success($candidate);
    }
}
