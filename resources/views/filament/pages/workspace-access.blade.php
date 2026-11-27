<x-filament-panels::page>
    <x-filament::tabs :contained="true">
        <x-filament::tabs.item
            :active="$activeTab === 'members'"
            wire:click="switchTab('members')"
        >
            {{ __('workspace_access.tabs.members') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'roles'"
            wire:click="switchTab('roles')"
        >
            {{ __('workspace_access.tabs.roles') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    <div class="mt-6">
        @if ($activeTab === 'members')
            @livewire(\App\Filament\Pages\WorkspaceAccess\WorkspaceAccessMembersTable::class)
        @else
            @livewire(\App\Filament\Pages\WorkspaceAccess\WorkspaceAccessRolesTable::class)
        @endif
    </div>
</x-filament-panels::page>
