<?php

namespace App\Filament\Pages\WorkspaceAccess;

use App\Models\User;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAccessMutationService;
use App\Support\Workspace\Rbac\Concerns\HandlesWorkspaceAccessExceptions;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspacePermission;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class WorkspaceAccessMembersTable extends Component implements HasActions, HasSchemas, HasTable
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
            ->heading(__('workspace_access.members.heading'))
            ->description(__('workspace_access.members.existing_users_only'))
            ->query(
                WorkspaceUser::query()
                    ->where('workspace_id', $workspace->id)
                    ->with([
                        'user:id,name,email,is_active',
                        'roles' => function ($query) use ($workspace): void {
                            $query
                                ->where('workspace_roles.workspace_id', $workspace->id)
                                ->select('workspace_roles.id', 'workspace_roles.name', 'workspace_roles.workspace_id')
                                ->orderBy('workspace_roles.name');
                        },
                    ])
                    ->orderBy(
                        User::query()
                            ->select('name')
                            ->whereColumn('users.id', 'workspace_users.user_id')
                            ->limit(1),
                    ),
            )
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('workspace_access.members.columns.user'))
                    ->description(fn (WorkspaceUser $record): string => (string) $record->user?->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $userQuery) use ($search): void {
                            $userQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('state')
                    ->label(__('workspace_access.members.columns.state'))
                    ->state(fn (WorkspaceUser $record): string => $this->membershipStateLabel($record))
                    ->badge()
                    ->color(fn (WorkspaceUser $record): string => $this->membershipStateColor($record)),
                TextColumn::make('roles.name')
                    ->label(__('workspace_access.members.columns.roles'))
                    ->badge()
                    ->placeholder(__('workspace_access.members.no_roles')),
            ])
            ->recordActions([
                Action::make('assignRole')
                    ->label(__('workspace_access.members.actions.assign_role'))
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        Select::make('role_id')
                            ->label(__('workspace_access.members.fields.role'))
                            ->options(fn (WorkspaceUser $record): array => $this->assignableRoleOptions($record))
                            ->getOptionLabelUsing(fn (string $value): ?string => WorkspaceRole::query()->find($value)?->name)
                            ->required()
                            ->native(false)
                            ->searchable(),
                    ])
                    ->action(function (WorkspaceUser $record, array $data): void {
                        $this->mutate(function () use ($record, $data): void {
                            app(WorkspaceAccessMutationService::class)->assignRole(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                                (string) $data['role_id'],
                            );
                        }, 'workspace_access.notifications.member_role_assigned');
                    })
                    ->visible(fn (WorkspaceUser $record): bool => $this->assignableRoleOptions($record) !== []),
                Action::make('removeRole')
                    ->label(__('workspace_access.members.actions.remove_role'))
                    ->icon('heroicon-o-minus-circle')
                    ->color('gray')
                    ->schema([
                        Select::make('role_id')
                            ->label(__('workspace_access.members.fields.role'))
                            ->options(fn (WorkspaceUser $record): array => $this->assignedRoleOptions($record))
                            ->getOptionLabelUsing(fn (string $value): ?string => WorkspaceRole::query()->find($value)?->name)
                            ->required()
                            ->native(false)
                            ->searchable(),
                    ])
                    ->action(function (WorkspaceUser $record, array $data): void {
                        $this->mutate(function () use ($record, $data): void {
                            app(WorkspaceAccessMutationService::class)->removeRole(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                                (string) $data['role_id'],
                            );
                        }, 'workspace_access.notifications.member_role_removed');
                    })
                    ->visible(fn (WorkspaceUser $record): bool => $this->assignedRoleOptions($record) !== []),
                Action::make('deactivateMembership')
                    ->label(__('workspace_access.members.actions.deactivate'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('workspace_access.members.actions.deactivate'))
                    ->modalDescription(__('workspace_access.members.confirm.deactivate'))
                    ->visible(fn (WorkspaceUser $record): bool => $record->is_active)
                    ->action(function (WorkspaceUser $record): void {
                        $this->mutate(function () use ($record): void {
                            app(WorkspaceAccessMutationService::class)->deactivateMembership(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                            );
                        }, 'workspace_access.notifications.membership_deactivated');
                    }),
                Action::make('activateMembership')
                    ->label(__('workspace_access.members.actions.activate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('workspace_access.members.actions.activate'))
                    ->modalDescription(__('workspace_access.members.confirm.activate'))
                    ->visible(fn (WorkspaceUser $record): bool => ! $record->is_active)
                    ->action(function (WorkspaceUser $record): void {
                        $this->mutate(function () use ($record): void {
                            app(WorkspaceAccessMutationService::class)->activateMembership(
                                $this->actor(),
                                $this->resolveAccessWorkspace(),
                                $record->id,
                            );
                        }, 'workspace_access.notifications.membership_activated');
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    public function render()
    {
        return view('filament.pages.workspace-access.members-table');
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

    private function membershipStateLabel(WorkspaceUser $record): string
    {
        if ($record->user === null || ! $record->user->is_active) {
            return __('workspace_access.members.state.user_inactive');
        }

        if (! $record->is_active) {
            return __('workspace_access.members.state.access_inactive');
        }

        return __('workspace_access.members.state.active');
    }

    private function membershipStateColor(WorkspaceUser $record): string
    {
        if ($record->user === null || ! $record->user->is_active) {
            return 'gray';
        }

        if (! $record->is_active) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * @return array<string, string>
     */
    private function assignableRoleOptions(WorkspaceUser $record): array
    {
        $workspace = $this->resolveAccessWorkspace();
        $assignedIds = $record->roles->pluck('id')->all();

        return WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->when($assignedIds !== [], fn (Builder $query) => $query->whereNotIn('id', $assignedIds))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function assignedRoleOptions(WorkspaceUser $record): array
    {
        return $record->roles
            ->pluck('name', 'id')
            ->all();
    }
}
