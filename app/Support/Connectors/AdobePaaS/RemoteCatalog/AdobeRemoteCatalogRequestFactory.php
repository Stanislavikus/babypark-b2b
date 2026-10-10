<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSBaseUrl;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

final class AdobeRemoteCatalogRequestFactory
{
    private const string ITEM_FIELDS = 'items[id,sku,name,attribute_set_id,type_id,status,updated_at,extension_attributes[category_links[category_id,position]],media_gallery_entries[file,media_type,disabled,types],custom_attributes[attribute_code,value]],search_criteria,total_count';

    private const string BOUNDARY_FIELDS = 'items[id],search_criteria,total_count';

    private const string COUNT_FIELDS = 'items[id],search_criteria,total_count';

    private const string CATEGORY_FIELDS = 'items[id,parent_id,name,is_active,position,level,path],search_criteria,total_count';

    public function __construct(
        private readonly OAuth1RequestSigner $signer,
    ) {}

    public function boundary(AdobePaaSRequestContext $context): RequestInterface
    {
        return $this->build($context, [
            'pageSize' => 1,
            'currentPage' => 1,
            'sortOrders' => [[
                'field' => 'entity_id',
                'direction' => 'DESC',
            ]],
        ], self::BOUNDARY_FIELDS);
    }

    public function page(
        AdobePaaSRequestContext $context,
        int $lastSeenEntityId,
        int $maxEntityId,
        int $pageSize,
    ): RequestInterface {
        if ($lastSeenEntityId < 0 || $maxEntityId < 1 || $lastSeenEntityId >= $maxEntityId) {
            throw new InvalidArgumentException('Invalid Adobe remote catalogue keyset boundary.');
        }

        if ($pageSize < 1 || $pageSize > 200) {
            throw new InvalidArgumentException('Adobe remote catalogue page size must be between 1 and 200.');
        }

        return $this->build($context, [
            'pageSize' => $pageSize,
            'currentPage' => 1,
            'filterGroups' => [
                ['filters' => [[
                    'field' => 'entity_id',
                    'value' => $lastSeenEntityId,
                    'condition_type' => 'gt',
                ]]],
                ['filters' => [[
                    'field' => 'entity_id',
                    'value' => $maxEntityId,
                    'condition_type' => 'lteq',
                ]]],
            ],
            'sortOrders' => [[
                'field' => 'entity_id',
                'direction' => 'ASC',
            ]],
        ], self::ITEM_FIELDS);
    }

    public function categoryPage(
        AdobePaaSRequestContext $context,
        int $pageSize,
        int $currentPage,
    ): RequestInterface {
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('Adobe remote category page size must be between 1 and 100.');
        }

        if ($currentPage < 1) {
            throw new InvalidArgumentException('Adobe remote category current page must be positive.');
        }

        return $this->build($context, [
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
            'sortOrders' => [[
                'field' => 'entity_id',
                'direction' => 'ASC',
            ]],
        ], self::CATEGORY_FIELDS, '/V1/categories/list');
    }

    public function boundedCount(AdobePaaSRequestContext $context, int $maxEntityId): RequestInterface
    {
        if ($maxEntityId < 1) {
            throw new InvalidArgumentException('Adobe remote catalogue maximum entity id must be positive.');
        }

        return $this->build($context, [
            'pageSize' => 1,
            'currentPage' => 1,
            'filterGroups' => [[
                'filters' => [[
                    'field' => 'entity_id',
                    'value' => $maxEntityId,
                    'condition_type' => 'lteq',
                ]],
            ]],
        ], self::COUNT_FIELDS);
    }

    /** @param array<string, mixed> $searchCriteria */
    private function build(
        AdobePaaSRequestContext $context,
        array $searchCriteria,
        string $fields,
        string $endpointPath = '/V1/products',
    ): RequestInterface {
        if ($context->storeCode === '') {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS store code must not be empty.');
        }

        $url = $this->endpoint($context, $endpointPath).'?'.http_build_query(
            ['searchCriteria' => $searchCriteria, 'fields' => $fields],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
        $request = new Request('GET', $url);

        return $request->withHeader('Authorization', $this->signer->sign(
            $request->getMethod(),
            (string) $request->getUri(),
            null,
            null,
            $context->credentials,
            new OAuth1SigningContext(bin2hex(random_bytes(16)), time()),
        ));
    }

    private function endpoint(AdobePaaSRequestContext $context, string $endpointPath): string
    {
        $baseUrl = AdobePaaSBaseUrl::parse($context->baseUrl);
        $parsed = parse_url($baseUrl->value);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS base URL must be an absolute URL.');
        }

        $path = rtrim($parsed['path'] ?? '', '/').'/rest/'.rawurlencode($context->storeCode).$endpointPath;
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $parsed['scheme'].'://'.$parsed['host'].$port.$path;
    }
}
