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
        /*
         * Measured on cabinet products (1400px + 640px viewports):
         *   input.left − th:nth-child(1).left = 80px (toolbar padding 24px + gap 16px + prefix 40px).
         */
        margin-inline-start: -80px;
        width: calc(100% + 80px);
    }

    .fi-ta-header-toolbar > .ms-auto > :not(.fi-ta-search-field) {
        flex-shrink: 0;
    }

    /* Cart toolbar children participate in the parent icon-group flex gap. */
    .bp-cart-toolbar {
        display: contents;
    }
</style>
