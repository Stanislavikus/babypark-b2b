<?php

namespace Database\Factories;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorConnectionCheck>
 */
class ConnectorConnectionCheckFactory extends Factory
{
    protected $model = ConnectorConnectionCheck::class;

    public function definition(): array
    {
        $account = ConnectorAccount::factory()->create();

        return [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => ConnectorConnectionCheckTrigger::Manual,
            'initiated_by_user_id' => null,
            'status' => ConnectorConnectionCheckStatus::Succeeded,
            'cause_category' => null,
            'actionability' => null,
            'error_code' => null,
            'http_status' => 200,
            'user_message_key' => null,
            'safe_message_parameters' => null,
            'technical_summary' => null,
            'vendor_request_id' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 100,
        ];
    }
}
