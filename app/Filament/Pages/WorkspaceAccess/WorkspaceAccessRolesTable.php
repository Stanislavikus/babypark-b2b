<?php

namespace App\Filament\Pages\WorkspaceAccess;

use App\Models\User;
use App\Models\WorkspaceRole;
use App\Services\Workspace\WorkspaceAccessMutationService;
use App\Support\Workspace\Rbac\Concerns\HandlesWorkspaceAccessExceptions;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspacePermission;
use App\Support\Workspace\Rbac\WorkspacePermissionLabels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class WorkspaceAccessRolesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use HandlesWorkspaceAccessExceptions;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use RequiresFreshWorkspacePermission;

    public function table(Table $table): Table
    {
        $workspace = $this->resolveAccessWorkspace();

        return $table
            ->heading(__('workspace_access.roles.heading'))
            ->query(
                WorkspaceRole::query()
                    ->where('workspace_id', $workspace->id)
                    ->withCount('permissions')
                    ->selectSub(
                        DB::table('workspace_user_roles')
                            ->selectRaw('count(*)')
                            ->whereColumn('workspace_role_id', 'workspace_roles.id')
                            ->where('workspace_id', $workspace->id),
                        'assignments_count',
                    )
                    ->orderBy('name'),
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('workspace_access.roles.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label(__('workspace_access.roles.columns.permissions'))
                    ->formatStateUsing(fn (int $state): string => trans_choice(
                        'workspace_access.roles.permissions_summary',
                        $state,
                        ['count' => $state],
                    )),
                TextColumn::make('assignments_count')
                    ->label(__('workspace_access.roles.columns.users'))
                    ->numeric(),
            ])
            ->headerActions([
                Action::make('createRole')
                    ->label(__('workspace_access.roles.actions.create'))
                    ->icon('heroicon-o-plus')
                    ->schema([
                        TextInput::make('name')
                            ->label(__('workspace_access.roles.fields.name'))
                            ->required()
                            ->maxLength(255),
                        CheckboxList::make('permissions')
                            ->label(__('workspace_access.roles.fields.permissions'))
                            ->options(WorkspacePermissionLabels::options())
                            ->columns(1)
                            ->bulkToggleable(),
                    ])
                    ->action(function (array $data): void {
                        $this->mutate(function () use ($data): void {
                            app(WorkspaceAccessMutationService::class)->createRole(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                (string) $data['name'],
                                array_values($data['permissions'] ?? []),
                            );
                        }, 'workspace_access.notifications.role_created');
                    }),
            ])
            ->recordActions([
                Action::make('renameRole')
                    ->label(__('workspace_access.roles.actions.rename'))
                    ->icon('heroicon-o-pencil-square')
                    ->schema([
                        TextInput::make('name')
                            ->label(__('workspace_access.roles.fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->default(fn (WorkspaceRole $record): string => $record->name),
                    ])
                    ->action(function (WorkspaceRole $record, array $data): void {
                        $this->mutate(function () use ($record, $data): void {
                            app(WorkspaceAccessMutationService::class)->renameRole(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                                (string) $data['name'],
                            );
                        }, 'workspace_access.notifications.role_renamed');
                    }),
                Action::make('editPermissions')
                    ->label(__('workspace_access.roles.actions.edit_permissions'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema(fn (WorkspaceRole $record): array => [
                        CheckboxList::make('permissions')
                            ->label(__('workspace_access.roles.fields.permissions'))
                            ->options(WorkspacePermissionLabels::options())
                            ->default($this->rolePermissionCodes($record))
                            ->columns(1)
                            ->bulkToggleable(),
                    ])
                    ->action(function (WorkspaceRole $record, array $data): void {
                        $this->mutate(function () use ($record, $data): void {
                            app(WorkspaceAccessMutationService::class)->updateRolePermissions(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                                array_values($data['permissions'] ?? []),
                            );
                        }, 'workspace_access.notifications.role_permissions_updated');
                    }),
                Action::make('deleteRole')
                    ->label(__('workspace_access.roles.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('workspace_access.roles.actions.delete'))
                    ->modalDescription(__('workspace_access.roles.confirm.delete'))
                    ->action(function (WorkspaceRole $record): void {
                        $this->mutate(function () use ($record): void {
                            app(WorkspaceAccessMutationService::class)->deleteRole(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                            );
                        }, 'workspace_access.notifications.role_deleted');
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    public function render()
    {
        return view('filament.pages.workspace-access.roles-table');
    }

    /**
     * @return list<string>
     */
    private function rolePermissionCodes(WorkspaceRole $record): array
    {
        return $record->permissions()
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @param  callable(): void  $callback
     */
    private function mutate(callable $callback, string $successMessageKey): void
    {
        try {
            $callback();
            $this->notifyWorkspaceAccessSuccess($successMessageKey);
        } catch (\Throwable $exception) {
            $this->handleWorkspaceAccessException($exception);
        }
    }
}
