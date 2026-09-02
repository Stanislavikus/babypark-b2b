@props([
    'text',
    'label',
    'copiedLabel' => null,
    'failedLabel' => null,
    'unavailableLabel' => null,
    'color' => 'gray',
    'size' => 'sm',
    'icon' => null,
    'timeoutMs' => 2000,
])

@php
    $copiedLabel = $copiedLabel ?? $label;
    $failedLabel = $failedLabel ?? __('ui.clipboard.failed');
    $unavailableLabel = $unavailableLabel ?? __('ui.clipboard.unavailable');
    $jsText = \Illuminate\Support\Js::from($text);
@endphp

<span
    x-data="{
        status: 'idle',
        timer: null,
        setStatus(next) {
            this.status = next;

            if (this.timer) {
                clearTimeout(this.timer);
            }

            if (next !== 'idle') {
                this.timer = setTimeout(() => this.status = 'idle', {{ (int) $timeoutMs }});
            }
        },
        fallbackCopy(value) {
            if (! document?.body || ! document.queryCommandSupported?.('copy')) {
                return 'unavailable';
            }

            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            try {
                return document.execCommand('copy') ? 'success' : 'failed';
            } catch (error) {
                return 'failed';
            } finally {
                document.body.removeChild(textarea);
                window.getSelection?.()?.removeAllRanges?.();
            }
        },
        async copy(value) {
            if (navigator?.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(value);
                    return 'success';
                } catch (error) {
                    const fallbackStatus = this.fallbackCopy(value);
                    return fallbackStatus === 'unavailable' ? 'failed' : fallbackStatus;
                }
            }

            return this.fallbackCopy(value);
        },
    }"
    x-on:click.stop
>
    <div class="space-y-1">
        <x-filament::button
            type="button"
            :color="$color"
            :size="$size"
            :icon="$icon"
            x-on:click="(async () => setStatus(await copy({{ $jsText }})))()"
        >
            <span x-show="status === 'idle'">{{ $label }}</span>
            <span x-show="status === 'success'" x-cloak>{{ $copiedLabel }}</span>
            <span x-show="status === 'failed'" x-cloak>{{ $failedLabel }}</span>
            <span x-show="status === 'unavailable'" x-cloak>{{ $unavailableLabel }}</span>
        </x-filament::button>

        <p
            x-show="status === 'failed' || status === 'unavailable'"
            x-cloak
            class="text-xs text-danger-700 dark:text-danger-300"
            role="status"
            aria-live="polite"
        >
            <span x-show="status === 'failed'" x-cloak>{{ $failedLabel }}</span>
            <span x-show="status === 'unavailable'" x-cloak>{{ $unavailableLabel }}</span>
        </p>
    </div>
</span>
