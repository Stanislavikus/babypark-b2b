<style>
    /* Shared Filament table toolbar layout (admin + cabinet). */
    .fi-ta-header-toolbar > .ms-auto {
        flex: 1 1 0%;
        min-width: 0;
        gap: 0.75rem; /* gap-3 — filter, columns, cart icon, sum */
    }

    .fi-ta-header-toolbar > .ms-auto > .fi-ta-search-field {
        flex: 1 1 0%;
        min-width: 0;
        max-width: none;
    }

    .fi-ta-header-toolbar > .ms-auto > .fi-ta-search-field .fi-input-wrp {
        width: 100%;
    }

    .fi-ta-header-toolbar > .ms-auto > :not(.fi-ta-search-field) {
        flex-shrink: 0;
    }

    /* Cart toolbar children participate in the parent icon-group flex gap. */
    .bp-cart-toolbar {
        display: contents;
    }
</style>
