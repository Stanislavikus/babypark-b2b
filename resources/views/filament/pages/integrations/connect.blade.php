<x-filament-panels::page>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('connectors.ui.integrations.connect.name_hint', ['name' => $generatedName]) }}
    </p>

    <form wire:submit="connect" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">
                {{ __('connectors.ui.integrations.actions.connect') }}
            </x-filament::button>

            <x-filament::button
                tag="a"
                color="gray"
                :href="\App\Filament\Pages\Integrations\Integrations::getUrl()"
            >
                {{ __('connectors.ui.integrations.actions.cancel') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
