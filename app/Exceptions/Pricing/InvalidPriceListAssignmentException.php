<?php

namespace App\Exceptions\Pricing;

use RuntimeException;

class InvalidPriceListAssignmentException extends RuntimeException
{
    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_CROSS_WORKSPACE = 'cross_workspace';

    public const REASON_INACTIVE = 'inactive';

    public const REASON_WORKSPACE_DEFAULT = 'workspace_default';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(
            self::REASON_NOT_FOUND,
            'Обраний прайс-лист не знайдено.',
        );
    }

    public static function crossWorkspace(): self
    {
        return new self(
            self::REASON_CROSS_WORKSPACE,
            'Прайс-лист належить іншому робочому простору.',
        );
    }

    public static function inactive(string $listName): self
    {
        return new self(
            self::REASON_INACTIVE,
            "Прайс-лист «{$listName}» неактивний і не може бути призначений.",
        );
    }

    public static function workspaceDefault(): self
    {
        return new self(
            self::REASON_WORKSPACE_DEFAULT,
            'Основний прайс-лист компанії не можна призначати напряму. Оберіть «За замовчуванням».',
        );
    }
}
