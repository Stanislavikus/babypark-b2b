# GAP-024 PR4 Filament 5 Visual Capture — Summary

## Execution

- **Script:** `scripts/gap-024-pr4-visual-capture.mjs`
- **Base URL:** http://127.0.0.1:8765
- **Compared against:** `docs/audits/visual-baselines/gap-024-filament4-pr3/` (PR3 Filament 4)

## Results

| Metric | Value |
|---|---|
| Target captures | 144 |
| Successful | 144 |
| Failed | 0 |
| Total runtime | 152.8s |
| WebP output size | ~4.79 MB |

## Output locations

1. **Durable:** `docs/audits/visual-baselines/gap-024-filament5-pr4/`
2. **Artifacts:** `/opt/cursor/artifacts/gap-024-pr4-filament5-visual/`

## Matrix

- 20 §16 surfaces × 3 themes × 2 viewports = 120 core
- +24 extended sub-states (s07 forms, s09 governance, s18 cabinet)
- **Total:** 144 WebP files

## Interaction coverage

| Surface | State |
|---|---|
| s06 | ViewAction slideOver (row click + Livewire fallback) |
| s10–12 | Connector account list / detail / connection-check history |
| s15 | Quantity set + cart dropdown open |
| s17 | `bpOpenLightbox` overlay |
| s20 | Success notification after add-to-cart |

## Failures

_None._

See `COMPARISON.md` for PR4 vs PR3 classification.
