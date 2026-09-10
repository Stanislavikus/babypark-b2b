<?php

namespace App\Support\Connectors\AdobePaaS\Exceptions;

use RuntimeException;

final class AdobeProductExportSetupRequiredException extends RuntimeException
{
    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    public function __construct(
        public readonly array $attributeSets,
    ) {
        parent::__construct('Adobe attribute set selection is required before product export can proceed.');
    }
}
