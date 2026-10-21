<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;

interface AdobeRemoteCatalogCategoryCatalogueReader
{
    public function readCatalogue(AdobePaaSRequestContext $context): AdobeRemoteCatalogCategoryCatalogue;
}
