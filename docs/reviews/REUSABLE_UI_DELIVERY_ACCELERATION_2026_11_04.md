# Reusable UI Delivery Acceleration — contract v2.3 (2026-11-04)

**Status:** `[Resolved — 2026-11-04 — Product Owner]`. Approved by the Product Owner on 2026-11-04 (D1–D3 = yes, conditional on no danger to development; the Verifier check found no conflict). Supersedes v1 (2026-10-27), v2, v2.1 and v2.2 (all 2026-11-04 except v1); none was committed.

**Type:** delivery-process campaign. No domain change: no new table, field, status, writer, storage owner or authorization rule. A set of **short, independent changes**, not a sequential programme.

**Verified against:** `develop` @ `3549aec`; PR #304 `feat/unified-media-source-ui` @ `e1a64fd`.

**Does not change:** the `[Resolved — 2026-10-25]` Media UI reuse decision, the `[Resolved]` Test Execution Cadence in `05`, or any other `[Resolved]` contract. On conflict the `[Resolved]` document wins.

**How it takes effect (v2.3).** On D1 = yes the documentation lands at once, in **one docs-only PR with two commits**: (1) this file + Documentation Map entries; (2) the standing process rules in `05` (*CI and test-speed work guardrails*). From the owner's `OK` on that PR the rules bind every later task. **Nothing in the documentation waits for PR #304, PR #305 or any CI run**; those PRs only have to meet the acceptance items of the work they carry (§3). After landing, the normative text of the standing rules lives in `05`; this file keeps the campaign-specific facts, priorities and Measurement Log, and on any difference `05` wins.

---

## 1. Priorities (ordered; each can ship on its own)

| # | Work | Rule |
|---|---|---|
| P1 | **Finish PR #304.** Independent review at the frozen HEAD, owner visual sign-off (2026-10-25 acceptance), owner `OK merge`. | Only fixes for review findings. No refactor in this branch. |
| P2 | **Fix the fork-escape defect in the test fixtures that re-runs the test suite (§3) — `LocalHttpServer` first, then the same pattern wherever it exists — and re-measure the gate.** | Never remove, skip or weaken a test. No production transport change (the evidence points at the test fixture). |
| P3 | **Continue Master Product #287.** Starts once the files shared with #304 are settled. | Does **not** wait for P2 or for any Media extraction. |
| P4 | **Record only measured results and rules that proved necessary.** | Numbers only in the Measurement Log (§7). No process added per visual tweak. |
| opt | **M1 — minimal Media extraction slice** (§4). | Trigger-based, never scheduled. |

## 2. Verified facts (evidence = file / line / SHA)

| # | Fact | Evidence |
|---|---|---|
| F1 | `MediaSourceArea` (39 lines) is presentation only; its docblock says it "never uploads, ingests, or reassigns an Original". | `app/Filament/Support/MediaSourceArea.php` @ `e1a64fd` |
| F2 | Upload and URL controls are defined per context: Brand `BrandResource.php:185` / `:237`; Asset `MediaAssetResource.php:111`; Product `ProductResource.php:170` / `:220`; a fourth upload definition in `EditProduct.php:244` (not inspected for constraints). | grep @ `e1a64fd` |
| F3 | **Duplication, measured.** Identical in every copy inspected: accepted-MIME list (5 types) ×3 (`BrandResource.php:194-200`, `ProductResource.php:179-185`, `MediaAssetResource.php:116-122`), `maxSize(20*1024)` ×3, `storeFiles(false)` ×3, `live()` ×3; `imagePreviewHeight('320')` ×2; the URL modal form (label, icon, `TextInput url required maxLength 2048`) ×2. **Drift already exists:** `validationMessages` (the same two hardcoded Ukrainian strings) is in Brand and Asset but missing in Product; the URL `placeholder` is only in Brand; URL error-mapping code differs between Brand and Product. **Context-specific:** `afterStateUpdated` bodies, `multiple()->appendFiles()` (Product only), writer calls, visibility. | files above |
| F4 | PR #304 has 37 commits over ~28 h: 10 test, 10 fix, 7 style, 5 feat, 2 refactor, 2 ci, 1 docs; two subjects repeat (3× and 2×). | `git log origin/develop..pr304` |
| F5 | `mysql-tests.yml` is one job: 9 filtered steps, then the full serial `php artisan test`, Pint, diff check. It has **no `timeout-minutes`**, and neither CI workflow has a `paths` filter (a docs-only PR pays the full gate). `playwright-browser-smoke.yml` is separate (own MySQL, installs, `timeout-minutes: 30`). | `.github/workflows/*.yml` @ `3549aec` |
| F6 | ≈ 390 test files / ≈ 3,601 test methods (grep, approximate); 75 migrations; ≥ 20 test/fixture files spawn child processes (grep list truncated), at least 10 under `tests/Integration/MySql`. `brianium/paratest` is absent from `composer.json` / `composer.lock`. | repo; laravel.com/docs/13.x/testing |
| F7 | `tests/e2e/seed-shared-media.php` runs only with `APP_ENV=e2e`, `CI=true`, MySQL `babypark_e2e` on `127.0.0.1`; `AGENTS.md` makes SQLite the supported Cursor Cloud database and native MySQL "non-canonical". ⇒ browser verification is effectively CI-only. | files above |
| F8 | Filament 5: "A custom Livewire component does not inherit the parent component's model scoping or authorization." | filamentphp.com/docs/5.x/schemas/custom-components |
| F9 | `07-TECH_STACK.md` already requires centralized reusable UI behavior (L103) and "the smallest shared abstraction" (L133). | `docs/07-TECH_STACK.md` |
| F10 | **TLS fixture control flow** (`tests/Unit/Connectors/Transport/ConnectorTlsVerificationTest.php`, `Support/LocalHttpServer.php`; also used by `ConnectorRequestSenderTest.php`). The test calls `pcntl_fork()` **inside PHPUnit**, so the child is a copy of the whole test process. The child branch is `runSingleRequestFromExportedSocket(…); exit(0);` (`LocalHttpServer.php:75-78`) with **no `try/catch/finally`**: any `Throwable` raised in the child skips `exit(0)` and unwinds into the child's copy of the PHPUnit stack. The parent's `close()` → `waitForHandler()` calls `pcntl_waitpid($pid, $status)` with no timeout and never reads `$status` (`:85-91`). The child's accepted stream has no `stream_set_timeout` (`:123-135`). `fwrite($client, $response)` is at `:138`. | files above |
| F11 | GitHub: "To trigger the `workflow_dispatch` event, your workflow must be in the default branch." A diagnostic workflow on a throwaway branch must use `push` / `pull_request`. | docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow |
| F12 | **A second fork-based fixture has the same pattern and was not covered by the first fix.** `tests/Unit/Connectors/Transport/Support/SlowChunkedBodyHttpServer.php`: `pcntl_fork()` at `:69`, `self::runHandler($state); exit(0);` at `:75-76` with no `try/catch/finally`, `pcntl_waitpid($this->handlerPid, $status)` with `$status` discarded at `:86`. Its writes are `@`-suppressed and checked (`writeAll`), but `fread` (`:128`) and `file_put_contents` (`:142`, `:154`, `:167`, `:168`) are not suppressed, so a notice/warning converted to an exception by the framework would escape the same way — **possible, not observed**. Used by `ConnectorRequestSenderTest::aborts_oversized_response_during_transfer_before_server_finishes`. `LocalHttpServer` is also used by about ten tests in `ConnectorRequestSenderTest.php` (`:64-380`), including size-limit tests (`:213`, `:251`). | files above @ `a79d018` (this file is identical to `3549aec`) |

## 2a. Mechanism H — "fork escape" (status: confirmed from logs by the Lead and from code; no independent reproduction yet)

**Mechanism.** The child's `fwrite` at `:138` meets a connection the client has already closed (expected in the wrong-hostname test, where the client aborts the handshake); the warning becomes an exception; with no `try/finally` it skips `exit(0)` and unwinds into the child's copy of PHPUnit. That copy records the TLS test as failed and **runs the rest of the suite itself**, prints its own summary and exits. The parent's `pcntl_waitpid` returns, the parent's TLS test passes, and the parent runs the same remainder again. The reported "test duration" is the child's whole run, and the job stays green because the child's exit status is discarded.

**Evidence read by the Lead from the primary logs** (run `40378623228`, #825, `e1a64fd`):

| Test class | First `PASS` | Repeated `PASS` |
|---|---|---|
| `ConnectorTransportLimitsTest` | 18:44:23 | 19:34:52 |
| `CurlClientFactoryTest` | 18:44:23 | 19:34:52 |
| `MasterProductMediaFoundationTest` | 18:59:31 | 19:52:26 |

288 test classes reappear after the failing TLS scenario. Two final summaries: 19:34:52 `1 failed, 15 skipped, 3899 passed`; 20:27:01 `15 skipped, 3900 passed` (3915 tests each). The log also contains `fwrite(): SSL operation failed … Broken pipe` at `LocalHttpServer.php:138`.

**Further runs read by the Lead** (10 more logs besides #825):

| Runs | TLS-test duration | Suite repeated |
|---|---|---|
| #789, #790, #791 | 0.33–0.49 s | no |
| #795, #797, #798 | 0.52–0.76 s | no |
| #818 | 2123.44 s | yes |
| #819 | 1707.29 s | yes |
| #821 | 3209.49 s | yes |
| #822 | 3403.70 s | yes |
| #825 | 3028.92 s | yes |

Each of the five anomalous logs has `Broken pipe`, two final suite results and repeated test classes.

**Verifier's consistency checks (arithmetic only; the Verifier has not seen the logs):** 18:44:23 → 19:34:52 = 50:29 (= 3029 s, matches 3028.92 s); 19:34:52 → 20:27:01 = 52:09; the first two classes pass at exactly the instant the child run starts (18:44:23) and at exactly the instant the parent resumes (19:34:52), which is what the mechanism predicts; 1 + 15 + 3899 = 3900 + 15 = 3915 in both summaries.

**Consequences.** (i) every full gate in an anomalous run executes the post-TLS remainder twice; (ii) a failure inside the child's re-run never fails the gate — an integrity gap, not only a speed problem; (iii) the expected saving (≈ 50 min per run) is **a projection, not a result**, and the spread of anomalous TLS durations (28–57 min) shows the saving varies.

**What the logs do not yet settle (they change the scope and the honesty of the claims):**
- *Q1 — regime change (partly answered).* The listed runs #789–#798 are clean and all five listed runs from #818 on are anomalous. **Read by the Lead:** the runner image changed between them — #798 (2026-10-21) ran on `ubuntu-24.04` (TLS test 0.76 s, no repetition), #818 (2026-10-25) on `ubuntu-26.04` (`Broken pipe`, suite repeated). `runs-on: ubuntu-latest` floats, so the change was silent. This is strong circumstantial evidence for *why it began*, **not** proof of which PHP/OpenSSL difference triggers the failed write; the fixture defect itself is confirmed from code and logs either way. Still open: whether runs #799–#817, #820, #823, #824 were clean or anomalous (the first anomalous run is not known). The fix goes into the fixture; returning to the old runner or changing production transport is not the remedy.
- *Q2 — hidden failures (partly answered, integrity gap observed).* **Read by the Lead:** the child run of #818 also contained a failure of `MasterProductMediaFoundationTest`; in that run the main run failed too, so the workflow was red. Failures confined to the child run would have been invisible to the gate. Still open: every failed test in the child summaries of #819, #821, #822, #825.
- *Q3 — clean baseline.* Total workflow and full-suite durations of the clean runs (#789–#798) are not recorded, so no saving can be stated yet.

*Independence of the proof:* the regression test of the fix (§3) reproduces the **mechanism** deterministically with a handler that throws, without relying on the TLS race.

### Not verified

Playwright #201 success; the targets ≤ 2 h (transfer) and ≤ 30 min (gate) are hypotheses of the Lead. The Verifier's own attempt to reproduce in a sandbox failed for an environmental reason (dependency installation blocked).

## 3. P2 — targeted fixture fix, then measure (separate PR; test fixtures, workflow files; no app code)

The first implementation is Draft PR #305 (`fix/ci-tls-fixture-fork-escape`); its current state lives in the PR, not in this file. The first review of it (by the session that drafted this contract — **not** an independent review) was AMEND: the fix of `LocalHttpServer` is sound and in scope, and steps 2–4 and the acceptance items below were tightened accordingly.

1. **Evidence attached first (no code):** raw log excerpts for #825; Q1 (log headers of #798 and #818, first anomalous run), Q2 (every failed test in the five child summaries) and Q3 (clean-run baseline) answered with run ids. The runner-image change and the #818 child-run failure are already recorded in §2a / §7 as read by the Lead; the excerpts are still owed.
2. **Fix the fixtures** (`LocalHttpServer` first; then `SlowChunkedBodyHttpServer` and every other test or fixture that calls `pcntl_fork`, F12 — each guarded or left with a written reason; no production transport change): the forked child can never return into PHPUnit's runner — guaranteed termination on any `Throwable`; the *expected* client-closed write failure is handled as normal; an unexpected child failure ends with a non-zero exit status; the parent reads `$status` and fails the test on a non-zero child. Candidate designs, with a recommendation: **A** (recommended) keep `pcntl_fork` and add the guard above; **B** run the fixture server as a separate process (`proc_open` / Symfony Process, already used by the concurrency tests) — only if A cannot guarantee termination without PHPUnit shutdown handlers.
3. **Regression test** for the abnormal handler exit, **run in an isolated PHP subprocess with a timeout** (a test that forks inside PHPUnit would, on a future regression, re-run the suite itself and end in a CI timeout instead of a red test): with a handler that throws inside the child, (a) the code after `serveOnceInBackground()` executes exactly once across the process tree, (b) the child terminates, (c) `waitForHandler()` surfaces a non-zero status as a test failure; plus a test that a benign closed-connection write does not fail the test. Show the test red on the old fixture (only inside the isolated subprocess) and green on the fix; paste both outputs.
4. **Both consumers** — `ConnectorTlsVerificationTest`, `ConnectorRequestSenderTest` — then the full MySQL gate on the frozen HEAD. The exit-status check is stricter than before: a test that closes the connection early on purpose (size-limit and abort scenarios) may now fail. Such a failure was previously hidden; classify each one with evidence as an *expected client close* (handled as normal in the fixture) or a *real defect*.
5. **Record the measured result** in §7 with run ids. Do not present the expected ≈ 50-minute saving as achieved.
6. **Safety net:** add `timeout-minutes` to the MySQL job and the full-suite step, set from the *measured clean-run* duration (not guessed).
7. **Then decide further levers from evidence** (profile top-30 classes; options: matrix for dominant classes; `--parallel` only if still needed, with `brianium/paratest` license/maintenance check and proven concurrency classes; cheaper test-DB bootstrap; a lightweight docs-only path, noting that a path-skipped workflow can leave a required check pending).

**Acceptance (any P2 change).**
1. *Test inventory, not a bare count:* for a CI-execution-only change the set of executed test IDs equals the baseline; otherwise every added or removed ID is listed and explained. No new unexplained skips.
2. *A second run on the same HEAD* only when the execution mode changes (parallel, matrix, DB bootstrap). An intermittent failure is closed by root cause plus a removed hazard, not by N green runs.
3. Pint, `git diff --check` and PR-scoped `cancel-in-progress` retained. Required-check names preserved, or the owner updates the ruleset.
4. After the fix, the log of the gate run contains each test class **once** and a single final summary.
5. A regression test for a child-process hazard runs the hazard in an isolated subprocess with a timeout, so a regression fails that test instead of re-running the suite.
6. Every `pcntl_fork` in `tests/` is guarded and has its exit status checked, or carries a written reason; the grep list is attached to the PR.

(Normative form of items 1–3 and of the fixture rule: `05` → *CI and test-speed work guardrails*, once landed.)

**If a lever under-delivers:** find out why, then take the next lever. If the cause stays unexplained after two attempts, escalate to independent review / Opus (existing project rule). Other work continues; nothing halts automatically.

## 4. M1 — minimal Media extraction slice (optional; trigger-based)

**Trigger:** the next real change to any Media source control, or a new authoring context that needs one. Not scheduled; not a precondition for P3. M1 is never a standing ban on touching Media: a small in-place fix of a confirmed defect does not need it.

**Scope — only the identical part (F3):** one factory for the upload constraints and messages, one factory for the URL modal form with a single error-mapping path. Each context keeps its own state handlers and writer call. Choose the smallest sufficient composition under `07` (the `MediaSourceArea::make` static-factory style is a valid precedent); a Schema component class or a nested Livewire component is **not** mandated, and nested Livewire needs explicit authorization and scoping tests (F8).

**Context semantics (from `[Resolved — 2026-10-25]`, must not be flattened):**

| Context | Sources | Cardinality | Commit | Writer owner |
|---|---|---|---|---|
| Brand | upload · library · URL | one logo | deferred; Brand Save authoritative | Brand logo binding + `OriginalImageUrlIngestService` |
| Master Product | upload · library · URL | gallery | immediate, under ProductMedia locking | `ProductMediaMutationService` + `OriginalImageUrlIngestService` |
| Asset editor | upload · URL | replace *this same* Original | deferred; explicit Save owns mutation | Assets lifecycle (`[Resolved — 2026-10-09]`) |

**Gate:** the 2026-10-25 acceptance evidence reproduced at that HEAD; no change to a writer, identity, storage or authorization (else Stop & Amend); final gate per `05`.

**Transfer-cost measurement.** When a *real* transfer to a new context happens, record elapsed time (branch creation → first green Chromium scenario on CI; CI waits count, owner review does not). ≤ 2 h is a **reference**, not a completion criterion. No artificial consumer is created.

**Guardrail for P3.** If #287 relocates the Media section, move its control definitions as they are (no edits), so a later M1 stays mechanical.

### [Resolved — 2026-11-05 — Product Owner: URL as Asset replacement bytes source]

The owner explicitly approved implementing a unified Brand-style source area in the existing Asset editor.
The approved URL option means **download a temporary image** using the existing
OriginalImageUrlIngestService::withDownloadedFile(), then pass it into the
**same** MediaAssetLifecycleService::replaceOriginal() writer as the local file.
The URL is staged in the EditRecord form; only the regular **Save** replaces the
stored bytes. For shared assets, the existing shared-usage confirmation still
applies. A failed download/validation or dedupe collision leaves the old
canonical Original and its usages intact. No new Original UUID, remote hotlink,
cross-Asset rebind, derivative behavior or storage authority is authorized.

Brand and Master Product keep all three sources; existing Asset Replace has
**two** real sources (native FilePond upload + URL). Library-as-Replace needs
a separate approved merge/rebind contract and is not part of this campaign.
Reuse the existing MediaSourceArea presentation and the smallest common
native URL modal factory, keeping each context's state/commit writer separate.
This amendment supersedes only the former "upload only"/"never URL" assertions
in §4 and N2; all other [Resolved] safety invariants remain frozen.

## 5. Non-negotiables

- **N1.** No change to MediaAsset identity, ProductMedia / VariantMedia persistence, the writer services, the Asset Replace lifecycle, the Brand logo binding, the storage owner or workspace authorization without Stop & Amend.
- **N2.** Context semantics stay as in §4; the Asset editor may use local upload or safely downloaded URL bytes, but never rebind an existing library Asset under Replace.
- **N3.** No nested Livewire component for a shared Media block by default (F8).
- **N4.** No test is removed, skipped, weakened or made non-required to gain speed (inventory rule, §3).
- **N5.** Final gate on the frozen HEAD stays as in `05`: full MySQL suite, Pint, diff check, applicable Chromium, then owner `OK`. Merge never authorizes deployment.
- **N6.** The 2026-10-12 visual contract and the 2026-10-25 acceptance bind every Media UI change.

## 6. Roles and autonomy

Owner: D1–D3, visual sign-off, business decisions, final `OK` for merge / deploy. Lead (GPT): sequencing and tasks; cites measurements only as classified in §7. Executor (Cursor): one change at a time, verbatim command output. Independent Verifier (separate Claude session): `OK` / `AMEND` / `STOP` with file:line + SHA. The session that drafted this file did a pre-flight check of the Lead's claims and a first review of PR #305; neither is the independent review of PR #304 required by P1, and neither came from a separate session.

**Autonomy.** The Lead investigates, fixes, verifies and continues without new stops for ordinary technical decisions. The owner is asked only for business decisions, for items that already require Stop & Amend under `05`, and for the final `OK` on merge / deploy.

## 7. Measurement Log (single place for numbers)

*Classification:* **verified** = run id + raw excerpt attached; **read by Lead** = run id, excerpt pending (may drive priority and the design of checks, not an acceptance baseline); **not verified**.

| Date | Item | Source | Value | Status |
|---|---|---|---|---|
| 2026-11-04 | MySQL workflow #825 @ `e1a64fd`, total | run `40378623228` | 115:39 | read by Lead |
| 2026-11-04 | Full-suite step, same run | same | 103:14 | read by Lead |
| 2026-11-04 | `ConnectorTlsVerificationTest`, #825 | same | 3028.92 s | read by Lead |
| 2026-11-04 | Final summaries, #825 | same | 19:34:52 `1 failed, 15 skipped, 3899 passed`; 20:27:01 `15 skipped, 3900 passed` | read by Lead |
| 2026-11-04 | Repeated `PASS` of 3 classes + 288 classes, #825 | same | see §2a | read by Lead |
| 2026-11-04 | TLS-test duration, clean runs #789–#791, #795, #797, #798 | Lead log reads | 0.33–0.76 s | read by Lead |
| 2026-11-04 | TLS-test duration + suite repeated, #818, #819, #821, #822 | Lead log reads | 2123.44 / 1707.29 / 3209.49 / 3403.70 s, repeated | read by Lead |
| 2026-11-04 | Playwright #201 @ `e1a64fd` | Lead report | success | not verified |
| 2026-11-04 | Q1: runner image, #798 (2026-10-21) vs #818 (2026-10-25); TLS test 0.76 s vs 2123.44 s | Lead log reads | `ubuntu-24.04` → `ubuntu-26.04` | read by Lead |
| 2026-11-04 | Q2: child run of #818 also contained a `MasterProductMediaFoundationTest` failure; main run failed too | Lead log reads | 1 extra failed class | read by Lead |
| TBD | Q1 rest: commits between the two HEADs; clean/anomalous status of #799–#817, #820, #823, #824 | log headers, `git log` | | |
| TBD | Q2 rest: failed tests in the child summaries of #819, #821, #822, #825 | raw logs | | |
| TBD | Q3: total / full-suite durations of clean runs #789–#798 | `gh run view` | | |
| TBD | Gate after the fixture fix: total, executed/skipped IDs, single summary | CI run | | |
| TBD | Top-30 slowest classes + category (after the fix) | | | |

## 8. Decisions of the Product Owner (answered 2026-11-04)

- **D1.** Approved 2026-11-04: this contract is `[Resolved]`, including the standing process rules in `05` (*CI and test-speed work guardrails*), landed in the same docs-only PR.
- **D2.** Freeze only an *uncoordinated change of the Media source-control contract* and any *repeated refactor* of those controls until M1 is triggered or declined. Still allowed: fixes for confirmed defects of PR #304; moving the Media section inside #287 without changing its behaviour; mandatory security / regression fixes; small in-place fixes of confirmed defects; every other area. *Recommendation: yes* (Lead and Verifier agree). **Approved 2026-11-04.**
- **D3.** Browser verification stays in CI Playwright + MySQL on the GitHub-hosted runner, measured, with a lightweight docs path; no second SQLite e2e environment. *Recommendation: yes* (Lead and Verifier agree). **Approved 2026-11-04.**

## 9. Closure

When P2 has shipped and P4 is recorded, set Status to `[Closed — <date>]`. The standing rules of patch C are already in `05` (landed with this file). Any rule that proved necessary *later* is promoted into `05-AI_WORKING_AGREEMENT.md` through a **separate small governance PR with owner sign-off** — never mixed into a fixture or CI PR; if M1 ran, the shared factories are named in `07`. Mark this file historical in the Documentation Map and remove the Map's UI-reading bullet (patch A2). This file never duplicates normative rules that `05` already carries.
