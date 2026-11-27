<?php

namespace App\Enums;

enum ConnectorErrorActionability: string
{
    case UserActionRequired = 'user_action_required';
    case AutomaticRetry = 'automatic_retry';
    case WorkspaceAdminRequired = 'workspace_admin_required';
    case SupportRequired = 'support_required';

    public function label(): string
    {
        return 'connectors.enums.error_actionability.'.$this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
