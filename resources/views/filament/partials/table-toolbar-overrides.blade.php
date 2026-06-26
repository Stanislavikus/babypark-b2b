<style>
    /* Shared Filament table toolbar layout (admin + cabinet). */
    .fi-ta-header-toolbar-search {
        flex: 1 1 0%;
        min-width: 0;
    }

    .fi-ta-header-toolbar-search .fi-ta-search-field {
        width: 100%;
    }

    .fi-ta-header-toolbar > .ms-auto {
        flex-shrink: 0;
        gap: 0.75rem; /* gap-3 — filter, columns, cart icon, sum */
    }

    .fi-ta-header-toolbar > .flex.shrink-0:empty {
        display: none;
    }

    /* Cart toolbar children participate in the parent icon-group flex gap. */
    .bp-cart-toolbar {
        display: contents;
    }
</style>
