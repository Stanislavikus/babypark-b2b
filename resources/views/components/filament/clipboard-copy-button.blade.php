@props([
    'text',
    'label',
    'copiedLabel' => null,
    'color' => 'gray',
    'size' => 'sm',
    'icon' => null,
    'timeoutMs' => 2000,
])

@php
    $copiedLabel = $copiedLabel ?? $label;
@endphp

<span
    x-data="{ copied: false }"
    x-on:click.stop
>
    <x-filament::button
        type="button"
        :color="$color"
        :size="$size"
        :icon="$icon"
        x-on:click="(async () => {
            if (! navigator?.clipboard?.writeText) return;
            try {
                await navigator.clipboard.writeText(@js($text));
                copied = true;
                setTimeout(() => copied = false, {{ (int) $timeoutMs }});
            } catch (e) {}
        })()"
    >
        <span x-show="!copied">{{ $label }}</span>
        <span x-show="copied" x-cloak>{{ $copiedLabel }}</span>
    </x-filament::button>
</span>
