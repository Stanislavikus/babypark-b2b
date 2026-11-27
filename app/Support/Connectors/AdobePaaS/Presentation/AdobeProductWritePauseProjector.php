<?php

namespace App\Support\Connectors\AdobePaaS\Presentation;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\SyncRunItem;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductWriteAccessClassification;

final class AdobeProductWritePauseProjector
{
    private const string COMMAND_EVIDENCE = 'command_evidence';

    private const string PERMISSION_DENIED_REASON = 'stock_write_permission_denied';

    private const string VERIFIED_WRITE_REASON = 'stock_write_verified';

    public function isPausedByProvenPermissionDenial(ConnectorAccount $account): bool
    {
        $credentialBoundary = ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->getKey())
            ->where('trigger', ConnectorConnectionCheckTrigger::CredentialsReplacement)
            ->where('status', ConnectorConnectionCheckStatus::Succeeded)
            ->whereNotNull('finished_at')
            ->max('finished_at');

        $items = SyncRunItem::withoutWorkspaceScope()
            ->select([
                'sync_run_items.*',
                'sync_runs.completed_at as run_completed_at',
                'sync_runs.created_at as run_created_at',
            ])
            ->join('sync_runs', 'sync_runs.id', '=', 'sync_run_items.sync_run_id')
            ->join('sync_configurations', 'sync_configurations.id', '=', 'sync_runs.sync_configuration_id')
            ->where('sync_run_items.workspace_id', $account->workspace_id)
            ->where('sync_configurations.workspace_id', $account->workspace_id)
            ->where('sync_configurations.connector_account_id', $account->getKey())
            ->where('sync_runs.mode', SyncRunMode::Live->value)
            ->where('sync_runs.semantic_operation', SyncSemanticOperation::Export->value)
            ->whereIn('sync_runs.status', [
                SyncRunStatus::Completed->value,
                SyncRunStatus::Failed->value,
            ])
            ->when(
                $credentialBoundary !== null,
                // Current schema timestamps are second-precision. Equality is kept
                // deliberately: discarding same-second evidence could hide a real
                // post-rotation permission denial. A stale same-second warning is
                // safer than a false all-clear when chronology is unknowable.
                fn ($query) => $query->where('sync_run_items.created_at', '>=', $credentialBoundary),
            )
            ->orderByDesc('sync_runs.completed_at')
            ->orderByDesc('sync_runs.created_at')
            ->orderByDesc('sync_run_items.created_at')
            ->orderByDesc('sync_run_items.id')
            ->cursor();

        $currentRecencyKey = null;
        $bucketHasPermissionDenial = false;
        $bucketHasVerifiedWrite = false;

        foreach ($items as $item) {
            $recencyKey = $this->recencyKey($item);

            if ($currentRecencyKey !== null && $recencyKey !== $currentRecencyKey) {
                $decision = $this->decisionForRecencyBucket(
                    $bucketHasPermissionDenial,
                    $bucketHasVerifiedWrite,
                );

                if ($decision !== null) {
                    return $decision;
                }

                $bucketHasPermissionDenial = false;
                $bucketHasVerifiedWrite = false;
            }

            $currentRecencyKey = $recencyKey;
            [$permissionDenied, $verifiedWrite] = $this->evidenceFlags($item);
            $bucketHasPermissionDenial = $bucketHasPermissionDenial || $permissionDenied;
            $bucketHasVerifiedWrite = $bucketHasVerifiedWrite || $verifiedWrite;
        }

        return $this->decisionForRecencyBucket(
            $bucketHasPermissionDenial,
            $bucketHasVerifiedWrite,
        ) ?? false;
    }

    /** @return array{bool, bool} */
    private function evidenceFlags(SyncRunItem $item): array
    {
        $permissionDenied = false;
        $verifiedWrite = false;

        foreach ($item->findings ?? [] as $finding) {
            if (! is_array($finding) || ($finding['code'] ?? null) !== self::COMMAND_EVIDENCE) {
                continue;
            }

            $context = $finding['context'] ?? null;

            if (! is_array($context)) {
                continue;
            }

            $reasonCode = $context['reason_code'] ?? null;
            $writeAttempts = (int) ($context['consequential_write_attempts'] ?? 0);

            if ($reasonCode === self::PERMISSION_DENIED_REASON
                && $writeAttempts > 0
                && ($context['write_access_classification'] ?? null) === AdobeProductWriteAccessClassification::PermissionDenied->value
                && $item->outcome === SyncLiveOutcome::NotApplied->value) {
                $permissionDenied = true;
            }

            if ($reasonCode === self::VERIFIED_WRITE_REASON
                && $writeAttempts > 0
                && (int) ($context['reconciliation_get_attempts'] ?? 0) > 0
                && $item->outcome === SyncLiveOutcome::Synchronized->value) {
                $verifiedWrite = true;
            }
        }

        return [$permissionDenied, $verifiedWrite];
    }

    private function recencyKey(SyncRunItem $item): string
    {
        return implode('|', [
            (string) $item->getAttribute('run_completed_at'),
            (string) $item->getAttribute('run_created_at'),
            (string) $item->created_at,
        ]);
    }

    private function decisionForRecencyBucket(bool $permissionDenied, bool $verifiedWrite): ?bool
    {
        // If contradictory evidence is indistinguishable at the persisted timestamp
        // precision, fail safe. A later independently timestamped verified WRITE
        // will clear the pause; an arbitrary UUID ordering must never do so.
        if ($permissionDenied) {
            return true;
        }

        if ($verifiedWrite) {
            return false;
        }

        return null;
    }
}
