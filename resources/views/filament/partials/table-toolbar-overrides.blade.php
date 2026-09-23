<style>
    /*
     * First-column inset — literal values from getComputedStyle(th).paddingLeft
     * on the live products page: 12px below 640px, 24px at 640px+ (px-3 / ps-6).
     */
    .fi-ta {
        --table-cell-inset: 12px;
    }

    @media (min-width: 640px) {
        .fi-ta {
            --table-cell-inset: 24px;
        }
    }

    .fi-ta thead th:first-child {
        padding-inline-start: var(--table-cell-inset) !important;
    }

    /* Shared Filament table toolbar layout (admin + cabinet). */
    .fi-ta-header-toolbar-search {
        flex: 1 1 0% !important;
        min-width: 0;
        margin-inline-start: 0 !important; /* override Filament 4 nth-child(2) ms-auto */
    }

    .fi-ta-header-toolbar-actions {
        flex-shrink: 0;
        margin-inline-start: 0; /* search already consumes middle space */
    }

    .fi-ta-header-toolbar-search .fi-ta-search-field {
        width: 100%;
        padding-inline-start: 0;
        box-sizing: border-box;
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

    /* Product list: hide ViewAction icon visually; row click uses recordAction('view'). */
    .bp-admin-row-view-action-hidden,
    .bp-workbench-row-view-action-hidden {
        display: none !important;
    }

    .fi-ta-actions-cell:has(.bp-admin-row-view-action-hidden) > .whitespace-nowrap {
        padding: 0;
        width: 0;
        overflow: hidden;
    }

    /*
     * Desktop SaaS navigation affordance: keep Filament's native sidebar state and
     * chevrons, but add the familiar hamburger glyph so collapse/expand intent is
     * immediately recognizable in both open and compact icon-rail states.
     */
    @media (min-width: 1024px) {
        #fi-main-sidebar .fi-sidebar-open-collapse-sidebar-btn,
        #fi-main-sidebar .fi-sidebar-close-collapse-sidebar-btn,
        .fi-topbar .fi-topbar-open-collapse-sidebar-btn,
        .fi-topbar .fi-topbar-close-collapse-sidebar-btn {
            width: auto !important;
            min-width: 2.5rem;
            gap: 0.2rem;
            padding-inline: 0.4rem !important;
        }

        #fi-main-sidebar .fi-sidebar-open-collapse-sidebar-btn::before,
        #fi-main-sidebar .fi-sidebar-close-collapse-sidebar-btn::before,
        .fi-topbar .fi-topbar-open-collapse-sidebar-btn::before,
        .fi-topbar .fi-topbar-close-collapse-sidebar-btn::before {
            content: '☰';
            display: inline-block;
            font-size: 0.8rem;
            font-weight: 700;
            line-height: 1;
        }
    }
</style>
