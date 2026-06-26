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
    }

    .fi-ta-header-toolbar > .ms-auto > .fi-ta-search-field .fi-input-wrp {
        width: 100%;
    }

    .fi-ta-header-toolbar > .ms-auto > :not(.fi-ta-search-field) {
        flex-shrink: 0;
    }

    /*
     * Align search + "Активні фільтри" with the first table column (Фото).
     * Toolbar uses px-4/sm:px-6; filter bar used px-3/sm:px-6 — unify to px-4/sm:px-6.
     * First body cell: ps-1 + inner px-3 (mobile) = 16px; sm:ps-3 + px-3 = 24px.
     */
    .fi-ta-filter-indicators {
        padding-inline-start: 1rem;
        padding-inline-end: 1rem;
    }

    @media (min-width: 640px) {
        .fi-ta-filter-indicators {
            padding-inline-start: 1.5rem;
            padding-inline-end: 1.5rem;
        }
    }

    /* Cart toolbar children participate in the parent icon-group flex gap. */
    .bp-cart-toolbar {
        display: contents;
    }
</style>
