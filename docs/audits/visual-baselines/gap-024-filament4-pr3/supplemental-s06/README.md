# Supplemental s06 — ViewAction-open like-for-like pair

## Why this exists

The durable PR1 baseline file
`docs/audits/visual-baselines/gap-024-filament3/s06-product-context-drawer-*.webp`
does **not** reliably show the manifest-required interaction state
(`/admin/products` → neutral row click → ViewAction slideOver). The original
PR3 after-captures compared against that defective baseline pair and were
therefore **not** a valid like-for-like visual gate.

PR1 baseline files are **not** overwritten (historical record preserved).

## How these captures were produced

| Side | Code state | Server |
|---|---|---|
| `pr1-f3-*` | Detached worktree at baseline commit `b45e01385778a9fd69b7051389452f447ad9a85d` | `http://127.0.0.1:8765` |
| `pr3-f4-*` | PR3 branch (Filament 4 / Tailwind 4) | `http://127.0.0.1:8000` |

Both sides:

* route `/admin/products`
* open ViewAction with product infolist visible (`Артикул` / product fields)
* themes: light / dark / system
* viewports: desktop **1280×900**, mobile **390×844**

Interaction: product-name cell click (manifest neutral row click). On narrow
Filament 3 mobile tables where the hit target was unreliable, the same
Livewire `mountTableAction('view', recordKey)` path used by the UI was used
as fallback after verifying the click path first.

## File inventory (12)

| File | Side |
|---|---|
| `pr1-f3-s06-product-context-viewaction-{light,dark,system}-{desktop,mobile}.webp` | Filament 3 reference |
| `pr3-f4-s06-product-context-viewaction-{light,dark,system}-{desktop,mobile}.webp` | Filament 4 PR3 |

Ephemeral copies / side-by-side review PNGs:

* `/opt/cursor/artifacts/gap-024-pr3-s06-supplemental/`
* `/opt/cursor/artifacts/gap-024-pr3-visual-review/s06-supplemental/`

## Verdict

This supplemental set **is** a valid like-for-like comparison of the
manifest-defined ViewAction-open state. Filament 4 slide-over chrome differs
from Filament 3 (framework delta), but interaction and product content match.
