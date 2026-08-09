<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshot;
use App\Support\Connectors\ConnectorAccountMerchandiserPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorUiFormatter;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ViewConnectorSchemaSnapshot extends Page
{
    protected static string $resource = ConnectorAccountResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.resources.connector-account-resource.pages.view-connector-schema-snapshot';

    public ConnectorAccount $account;

    public string $sourceLabel;

    public ?string $capturedAt;

    public int $fieldCount;

    public ?string $snapshotStateLabel;

    public function getTitle(): string|Htmlable
    {
        return __('connectors.ui.snapshot.title');
    }

    public function mount(int|string $record, string $snapshot): void
    {
        $this->account = $this->resolveAccountRecord($record);
        $snapshotRecord = $this->resolveSnapshotRecord($snapshot);

        $uiState = app(ConnectorAccountUiState::class);

        $this->sourceLabel = $uiState->schemaSourceLabel($snapshotRecord->schemaSource);
        $this->capturedAt = ConnectorUiFormatter::formatDateTime($snapshotRecord->captured_at);
        $this->fieldCount = $snapshotRecord->field_count;
        $this->snapshotStateLabel = $uiState->snapshotStateLabel($snapshotRecord);
    }

    protected function resolveAccountRecord(int|string $key): ConnectorAccount
    {
        $record = ConnectorAccountResource::getEloquentQuery()
            ->whereKey($key)
            ->firstOrFail();

        abort_unless(auth()->user()?->can('view', $record), 403);

        $record = ConnectorAccountMerchandiserPresentation::sanitizeRecord(
            $record,
            auth()->user(),
        );

        if (! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            $record->makeHidden([
                'credentials',
                'settings',
                'base_url',
                'auth_profile',
            ]);
        }

        return $record;
    }

    protected function resolveSnapshotRecord(string $snapshotId): ConnectorSchemaSnapshot
    {
        $snapshot = ConnectorSchemaSnapshot::query()
            ->where('connector_account_id', $this->account->getKey())
            ->whereKey($snapshotId)
            ->with([
                'schemaSource:id,label',
                'previousSnapshot:id,canonical_hash',
            ])
            ->first();

        if ($snapshot === null) {
            throw (new ModelNotFoundException)->setModel(ConnectorSchemaSnapshot::class, [$snapshotId]);
        }

        $snapshot->makeHidden([
            'canonical_hash',
        ]);

        if ($snapshot->schemaSource !== null) {
            $snapshot->schemaSource->makeHidden([
                'endpoint_path',
            ]);
        }

        if ($snapshot->previousSnapshot !== null) {
            $snapshot->previousSnapshot->makeHidden([
                'canonical_hash',
            ]);
        }

        return $snapshot;
    }
}
