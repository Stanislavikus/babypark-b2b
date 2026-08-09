@php
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
@endphp

@props([
    'debounce' => '500ms',
    'onBlur' => false,
    'placeholder' => __('filament-tables::table.fields.search.placeholder'),
    'wireModel' => 'tableSearch',
])

@php
    $wireModelAttribute = $onBlur ? 'wire:model.blur' : "wire:model.live.debounce.{$debounce}";
@endphp

<div
    x-id="['input']"
    {{ $attributes->class(['fi-ta-search-field']) }}
>
    <label x-bind:for="$id('input')" class="fi-sr-only">
        {{ __('filament-tables::table.fields.search.label') }}
    </label>

    {{-- Prefix icon omitted: inline-prefix offsets input.left from the first table column edge. --}}
    <x-filament::input.wrapper :wire:target="$wireModel">
        <x-filament::input
            :attributes="
                (new FilamentComponentAttributeBag)->merge([
                    'autocomplete' => 'off',
                    'maxlength' => 1000,
                    'placeholder' => $placeholder,
                    'type' => 'search',
                    'wire:key' => $this->getId() . '.table.' . $wireModel . '.field.input',
                    $wireModelAttribute => $wireModel,
                    'x-bind:id' => '$id(\'input\')',
                    'x-on:keyup' => 'if ($event.key === \'Enter\') { $wire.$refresh() }',
                ], escape: false)
            "
        />
    </x-filament::input.wrapper>
</div>
