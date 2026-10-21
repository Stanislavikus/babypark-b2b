# Task 4B-0 — Connector Account Visual Contract

**NON-RUNTIME DESIGN CONTRACT**

This directory is an isolated, fixture-backed visual prototype for Task 4B-0
Stop-and-Amend. It is **not** imported by Laravel, has no production routes,
and contains **no real credentials**.

## Purpose

Allow non-technical review of the connector operational workflow before
migrations or live API integration land in Task 4B-1 / 4B-2.

## Screens (six states)

1. **Connections list** — platform, account, status, attention message
2. **Connection settings** — PaaS vs SaaS, masked secrets, test/save
3. **Connection check result** — success and error variants (401 vs 403)
4. **Discovery result** — field list with filter panel, diff summary
5. **Diff detail** — added/removed/changed field inspection
6. **Activity history** — checks and discovery runs

## Fixture scenarios

- 1 connected Adobe account
- 1 not-yet-tested account
- Invalid credentials, insufficient permissions, vendor outage
- First discovery, repeated discovery (+3/−1/2 changed), no-change run
- Failed discovery after prior successful snapshot
- Six Task 3 golden fields (`sku`, `name`, `description`, `short_description`, `category`, `status`)

## How to open

```bash
# From repository root — any static file server works
python3 -m http.server 8765 --directory docs/prototypes/task-4b0-connector-account
# Open http://localhost:8765/index.html
```

Or open `index.html` directly in a browser (file://).

## Screenshots

Captured evidence lives in `screenshots/` (desktop 1440px, mobile 375px,
boundary 767/768px, dark mode samples).

## Removal

This prototype may be removed or replaced in Task 4B-1 only through an
explicit design decision.
