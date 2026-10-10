# Product Workbench — Classification Catalogue Real Magento Evidence — 2026-09-24

> **Status: READ-only real-target implementation evidence.**
>
> Base: `develop @ 34df2669afd12bebd268452927679cbe9cd84123`.
> No Magento WRITE was performed.

## Goal

Verify that complete provider Category and Attribute Set catalogues are materially different from
"values already referenced by current Products", and verify the exact Magento Category payload shape
needed by the 2026-09-24 resolved classification-catalogue addendum.

## Real target evidence

Current Remote Catalogue snapshot:

- Products: **19**.

Magento Category catalogue from paged `GET /V1/categories/list`:

- total Category rows: **28**;
- explicit active rows: **26**;
- explicit inactive rows: **1**;
- Magento structural Root Catalog (`level=0`) omits `is_active`; this is provider-unknown metadata,
  not evidence that the root is active or inactive;
- active non-root Product-target Categories: **24**;
- Category IDs referenced by current Products: **2**;
- active selectable Categories with **0 current Products: 22**.

Examples of active empty Categories observed on the target include:

- `Дитячі меблі`;
- `Товари для мам`;
- `Дитяче купання та гігієна`;
- `Дитячі іграшки`.

No active Category under an explicitly inactive ancestor was present in this snapshot, but the
resolved UI contract still requires that warning when such a provider state occurs.

Persisted Magento Product Attribute Sets:

- current sets: **4** — IDs `4`, `9`, `10`, `11`;
- sets referenced by current remote Products: **9**, **10**;
- current sets with **0 Products**: `4 Default`, `11 Attribute Set - Дитячі меблі`.

## Consequences

1. A selector derived only from current Product usage would hide **22/24** currently valid active
   Product-target Categories on this target.
2. It would also hide **2/4** current Attribute Sets.
3. Full provider catalogues are therefore required for new Product preparation and future AI
   recommendations.
4. Category identity remains `workspace + connector_account + external_category_id`; parent/path/name
   are mutable metadata.
5. Root `is_active` must remain nullable/unknown because the real standard response omits it.
6. The existing Stage-2 finding remains confirmed: standard REST responses expose Product Attribute
   Sets, Groups, Attributes and Attribute↔Set applicability, but not authoritative existing
   Attribute→Group placement. Do not infer it.

## Runtime acceptance implication

The first merchant classification UX must be able to select active provider Categories and current
Attribute Sets even when their current Product usage count is zero. Category zero-usage selection
requires the resolved explicit confirmation warning.
