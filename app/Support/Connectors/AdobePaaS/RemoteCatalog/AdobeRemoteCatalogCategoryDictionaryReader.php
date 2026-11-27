<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;

interface AdobeRemoteCatalogCategoryDictionaryReader
{
    /** @return array<string, string> external category id => breadcrumb path */
    public function read(AdobePaaSRequestContext $context): array;
}
