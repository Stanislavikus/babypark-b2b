{{-- Visible action participates in the native Filament grid; the existing
     FilePond instance remains the only uploader and the whole dashed drop target. --}}
<x-filament::button
    type="button"
    color="gray"
    outlined
    icon="heroicon-o-arrow-up-tray"
    class="bp-brand-media-browse-button"
    x-on:click="window.FilePond?.find($el.closest('.bp-brand-media-dropzone')?.querySelector('.filepond--root'))?.browse()"
>
    {{ __('media_ui.upload') }}
</x-filament::button>
