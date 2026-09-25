<?php

namespace App\Exceptions\Pricing;

use RuntimeException;

class InvalidCustomerBatchException extends RuntimeException
{
    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_CROSS_WORKSPACE = 'cross_workspace';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string|int $customerId): self
    {
        return new self(
            self::REASON_NOT_FOUND,
            "Клієнта з ідентифікатором {$customerId} не знайдено.",
        );
    }

    public static function crossWorkspace(string|int $customerId): self
    {
        return new self(
            self::REASON_CROSS_WORKSPACE,
            "Клієнт {$customerId} належить іншому робочому простору.",
        );
    }
}
