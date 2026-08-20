<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeProductCommandCompilationException extends \RuntimeException
{
    public static function blockingSemanticFindings(): self
    {
        return new self('Adobe Product command compilation blocked by semantic findings.');
    }

    public static function missingSimpleProductOperation(): self
    {
        return new self('Adobe Product command requires a simple_product semantic operation.');
    }

    public static function multipleSimpleProductOperations(): self
    {
        return new self('Adobe Product command requires exactly one simple_product semantic operation.');
    }

    public static function unsupportedOperation(string $operation): self
    {
        return new self('Unsupported Adobe Product semantic operation: '.$operation);
    }

    public static function missingField(string $field): self
    {
        return new self('Adobe Product desired state is missing required field: '.$field);
    }

    public static function invalidResolvedPrice(string $aspect): self
    {
        return new self('Adobe Product desired state has invalid resolved price aspect: '.$aspect);
    }

    public static function unresolvedMappingBinding(string $bindingId): self
    {
        return new self('Adobe Product command cannot resolve external field key for mapped binding: '.$bindingId);
    }
}
