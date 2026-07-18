# 07-TECH_STACK.md

## Purpose

This document is a short implementation guardrail for Cursor / AI coding agents.

It does not replace the architecture documents. It tells the agent which stack and existing patterns must be used when implementing UI from `06-UI_DESIGN_SYSTEM.md`.

The goal is simple: extend the current Laravel / Filament application. Do not invent a second frontend architecture.

---

## Application Stack

- Backend framework: Laravel.
- UI runtime: Livewire + Alpine.js where needed.
- Admin UI: Filament.
- B2B buyer UI: Filament panel / Filament-based pages for the current MVP.
- CSS: Tailwind CSS utility classes through the existing Filament / Tailwind setup.
- Icons: Heroicons through Filament by default.
- Theme: Filament theming, CSS variables and approved design tokens.
- Appearance: Light / Dark / System mode through Filament and Tailwind dark-mode support.

Do not introduce React, Vue, Next.js, Nuxt, Inertia, a custom SPA, a second design system or a separate storefront frontend unless a later architecture document explicitly approves it.

---

## Current Panels

The current application has two primary Filament panels:

- `/admin` — merchant / staff administration panel.
- `/cabinet` — B2B buyer cabinet / storefront panel.

These panels have different users, permissions and visibility rules.

Do not mix admin-only fields into the buyer UI.
Do not expose cost, profitability, internal status, source-of-truth or connector details to B2B buyers.

---

## Rules for AI / Cursor

Before writing UI code, read:

- `05-AI_WORKING_AGREEMENT.md`
- `06-UI_DESIGN_SYSTEM.md`
- this file
- the existing Filament resource/page/component that is being modified

When implementing UI:

- Extend existing Filament components and patterns first.
- Use Filament tables, forms, actions, infolists, panels, modals/drawers and notifications where they fit.
- Use Tailwind utility classes for layout and spacing.
- Use Alpine.js only for lightweight local interactions such as small toggles, focus behavior or quantity stepper UI.
- Use Livewire / Filament actions for server-round-trip operations such as search, filters, cart updates, order submission and persistence.
- Add new Livewire components only when Filament cannot reasonably express the interaction.
- Do not create a parallel custom component system.
- Do not write large custom CSS files.
- Do not use inline styles except for unavoidable token-driven values.
- Do not introduce new npm packages without human approval.
- Do not move business logic into Blade, Alpine or JavaScript.
- Do not duplicate Pricing, Availability, Order, Connector or Attribute Dictionary logic inside UI components.

---

## B2B Storefront Stack

For the MVP, the B2B storefront/cabinet uses the same Laravel + Filament + Livewire + Tailwind stack.

The B2B buyer experience may look simpler and more storefront-like, but it must still be implemented by extending approved Filament / Livewire patterns.

The B2B storefront is not a separate React/Vue storefront, not a marketplace, not a page builder and not a CMS theme system.

Public/anonymous catalogue behavior, custom storefront frontend or headless storefront rendering requires a separate approved architecture decision before implementation.

---

## File and Code Conventions

Use existing project structure before creating new directories.

Expected conventions:

- Filament admin resources/pages/actions stay under the existing admin Filament namespace.
- Filament cabinet resources/pages/actions stay under the existing cabinet Filament namespace.
- Shared domain/UI helper logic goes into existing support classes where appropriate.
- Shared Blade partials stay under existing `resources/views/filament/...` conventions.
- Reusable UI behavior should be centralized; do not copy/paste table, lightbox, availability or cart logic between panels.
- Legacy cabinet Livewire code must not be revived or extended unless the human explicitly approves it.

If the exact existing path is unclear, inspect the project before creating files.

---

## Existing Shared Patterns to Prefer

Prefer existing shared project patterns for:

- product table toolbar overrides;
- data list search/filter toolbar for non-Eloquent read models
  (`resources/views/components/filament/data-list-toolbar.blade.php`) —
  see "Data List Search & Filter Pattern" in `06-UI_DESIGN_SYSTEM.md`;
- shared data-list toolbar uses a one-row `md` responsive contract:
  desktop controls remain inline; below `md`, secondary controls move
  into one public-Filament overflow panel. The main header row must not
  use `flex-col`, `flex-wrap`, or a different mode-switch breakpoint.
  Vertical overflow-panel content may use `flex-col`, and removable
  indicator chips below the header may wrap. Do not use runtime width
  detection, vendor table views, or duplicate Filament form containers
  for this behavior.
- product image thumbnail and lightbox behavior;
- product column visibility;
- product panel visibility;
- catalogue row data preparation;
- session cart behavior;
- brand/theme tokens.

If a required shared pattern is missing, propose the smallest shared abstraction. Do not create one-off duplicated logic inside admin and cabinet separately.

---

## Styling Rules

- Use Filament and Tailwind defaults first.
- Use design tokens from `06-UI_DESIGN_SYSTEM.md` for accent, availability, status and theme behavior.
- Do not apply raw user-selected accent colors directly to buttons, text, links or focus states.
- Do not hardcode light-only UI surfaces; dark mode must remain readable.
- Do not introduce decorative visual noise, AI sparkle icons, animated dashboards or marketing-style UI.

---

## Data and Domain Boundaries

UI code may display domain data. It must not redefine domain rules.

The UI must call or reuse approved domain/application logic for:

- price display;
- buyer-specific price resolution;
- profitability / markup calculation;
- availability display;
- hidden stock protection;
- order status transitions;
- cart/order submission;
- source-of-truth field locking;
- connector field mapping;
- attribute dictionary anti-duplication.

If a UI task requires a new field, new persisted preference, new status, new calculation, new order action or new buyer-facing capability, stop and ask for a domain/documentation patch before coding.

---

## Task Prompt Template for Cursor

Use this structure for implementation tasks:

```text
Read these files before writing any code:
- 05-AI_WORKING_AGREEMENT.md
- 06-UI_DESIGN_SYSTEM.md (sections: <specific sections>)
- 07-TECH_STACK.md

Task:
<one concrete implementation task>

Definition of done:
- <what must work>
- <what must remain unchanged>
- <what must not appear>

Do not:
- <specific prohibitions for this task>
```

Tasks should be small enough to test visually after each step.

Recommended implementation order:

1. Admin product table defaults / toolbar / column visibility.
2. Product context drawer.
3. Quantity selector and cart behavior.
4. B2B buyer table/card view.
5. Mobile adaptation.
6. Polish, accessibility and edge states.

---

## Final Rule

Use the existing Laravel / Filament product. Do not build a new frontend inside it.
