<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Jobs\Connectors\ConnectorConnectionRecoveryJob;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;

final class ConnectorConnectionRecoveryScheduler
{
    /** @var list<int> */
    private const DELAYS_SECONDS = [3600, 7200, 14400, 28800, 57600, 115200];

    public function scheduleIfEligible(
        string $workspaceId,
        string $connectorAccountId,
        string $sourceCheckId,
    ): void {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        $source = ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('id', $sourceCheckId)
            ->first();

        if (! $this->isEligible($account, $source)) {
            return;
        }

        $delaySeconds = $this->nextDelaySeconds($source);

        if ($delaySeconds === null) {
            return;
        }

        ConnectorConnectionRecoveryJob::dispatch(
            $workspaceId,
            $connectorAccountId,
            $sourceCheckId,
        )->delay(now()->addSeconds($delaySeconds));
    }

    private function isEligible(?ConnectorAccount $account, ?ConnectorConnectionCheck $source): bool
    {
        if ($account === null || $source === null || ! $account->is_enabled) {
            return false;
        }

        if (
            $account->connection_status !== ConnectorAccountConnectionStatus::TemporarilyUnavailable
            || $account->last_error_actionability !== ConnectorErrorActionability::AutomaticRetry
            || $source->status !== ConnectorConnectionCheckStatus::Failed
            || $source->actionability !== ConnectorErrorActionability::AutomaticRetry
            || $source->finished_at === null
            || $account->last_error_at === null
        ) {
            return false;
        }

        return $account->last_error_at->equalTo($source->finished_at);
    }

    private function nextDelaySeconds(ConnectorConnectionCheck $source): ?int
    {
        if ($source->trigger !== ConnectorConnectionCheckTrigger::Scheduled) {
            return self::DELAYS_SECONDS[0];
        }

        $scheduledFailureCount = 0;
        $seenSource = false;

        $rows = ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('workspace_id', $source->workspace_id)
            ->where('connector_account_id', $source->connector_account_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(count(self::DELAYS_SECONDS) + 8)
            ->get();

        foreach ($rows as $row) {
            if (! $seenSource) {
                if ($row->id !== $source->id) {
                    continue;
                }

                $seenSource = true;
            }

            if (
                $row->trigger !== ConnectorConnectionCheckTrigger::Scheduled
                || $row->status !== ConnectorConnectionCheckStatus::Failed
                || $row->actionability !== ConnectorErrorActionability::AutomaticRetry
            ) {
                break;
            }

            $scheduledFailureCount++;
        }

        return self::DELAYS_SECONDS[$scheduledFailureCount] ?? null;
    }
}
