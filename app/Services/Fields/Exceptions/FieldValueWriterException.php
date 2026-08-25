<?php

namespace App\Services\Fields\Exceptions;

use RuntimeException;

/**
 * Base class for the bounded, Product/Field Dictionary exception taxonomy
 * thrown by the GovernedDynamicFieldValueWriter (GAP-028A).
 *
 * Every concrete exception in this directory MUST extend this class.
 * Do not introduce exceptions that bypass this hierarchy; the
 * catch-by-base contract is used by the writer to surface typed failures
 * to callers (Magento Receive, CSV/Smart Import, Google Sheets, ERP/1C,
 * product-card editing) without leaking implementation details.
 */
abstract class FieldValueWriterException extends RuntimeException
{
    public static function writerDisabled(): never
    {
        throw new \LogicException(
            'GovernedDynamicFieldValueWriter is not bootstrapped for this build.'
        );
    }
}
