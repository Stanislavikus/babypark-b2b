# Stage 2B — Option Mapping Remediation (visual contract)

Static fixture-backed reference for the nested Option Mapping surface on
`ManageSyncFieldOptionMappings`.

## Renderable prototype

Open **`index.html`** in a browser. Tabs cover merchant states described in Stage 2B.

## Covered states

1. manage actor + unmapped values
2. manage actor + mapped values
3. view-only actor
4. all current values mapped
5. external mapped value no longer available
6. authoritative external choices unavailable
7. Preview → Option Mapping remediation
8. stale/orphan correspondence section
9. mobile/narrow layout

## Verification

Automated coverage: `tests/Feature/Sync/Stage2BOptionMappingTest.php`
