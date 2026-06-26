<style>
    /* Shared Filament table toolbar layout (admin + cabinet). */
    .fi-ta-header-toolbar > .ms-auto {
        flex: 1 1 0%;
        min-width: 0;
        margin-inline-start: 0 !important;
        gap: 0.75rem; /* gap-3 — filter, columns, cart icon, sum */
    }

    .fi-ta-header-toolbar > .flex.shrink-0:empty {
        display: none;
    }

    .fi-ta-header-toolbar > .ms-auto > .fi-ta-search-field {
        flex: 1 1 0%;
        min-width: 0;
        max-width: none;
        /* Align search left edge with first column (fi-ta-cell sm:ps-3 + inner px-3). */
        margin-inline-start: -0.25rem;
    }

    @media (min-width: 640px) {
        .fi-ta-header-toolbar > .ms-auto > .fi-ta-search-field {
            margin-inline-start: -0.75rem;
        }
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
