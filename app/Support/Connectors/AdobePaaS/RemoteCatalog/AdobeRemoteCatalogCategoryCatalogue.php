<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use Carbon\CarbonImmutable;

final readonly class AdobeRemoteCatalogCategoryCatalogue
{
    /**
     * @param  list<array{
     *     external_category_id:string,
     *     parent_external_category_id:?string,
     *     name:?string,
     *     provider_path:?string,
     *     breadcrumb:string,
     *     level:int,
     *     position:?int,
     *     is_active:?bool
     * }>  $categories
     */
    public function __construct(
        public CarbonImmutable $capturedAt,
        public array $categories,
    ) {}

    /** @return array<string, string> external category id => breadcrumb */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->categories as $category) {
            $paths[$category['external_category_id']] = $category['breadcrumb'];
        }

        return $paths;
    }
}
