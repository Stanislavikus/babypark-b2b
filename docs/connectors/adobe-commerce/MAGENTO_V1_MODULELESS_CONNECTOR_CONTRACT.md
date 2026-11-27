# Magento / Adobe Commerce V1 — Moduleless Connector Contract

[Resolved Product Decision — 2026-09-24]

**Status:** NORMATIVE / NON-NEGOTIABLE for the standard Magento / Adobe Commerce V1 connector.

## Product definition

Magento / Adobe Commerce V1 is a universal **zero-install** connector built on the
standard Adobe Commerce / Magento Admin REST API.

The merchant connects an ordinary supported Magento / Adobe Commerce account by
providing the standard connection credentials and account/store context.

The standard Magento V1 path **MUST NOT require** installation of any
BabyPark / B2B Platform Magento module, plugin, theme, core patch, database
trigger, direct database access path, custom Magento endpoint, sidecar agent, or
other target-side BabyPark software.

The platform adapts to Magento. Magento is not modified to adapt to the platform.

## Architectural invariant

The standard V1 architecture is:

```text
B2B Platform canonical domain/runtime
        ↕
standard Adobe Commerce / Magento Admin REST API
```

It is **not**:

```text
B2B Platform
        ↕
mandatory BabyPark Magento module / custom endpoint
        ↕
Magento
```

Any proposal that makes target-side BabyPark software mandatory for normal
Magento V1 operation is a **Product Architecture Change**, not an implementation
detail.

## Implementation rule

Before designing a new Magento runtime seam, the executor MUST:

1. inspect current `origin/develop`;
2. inspect the existing Adobe/Magento transport/runtime code;
3. inspect current certification evidence and the Product Field Matrix;
4. inspect Git history for an existing or previously disabled implementation;
5. reuse, restore, or connect existing standard-API seams before inventing a new
   Magento integration architecture;
6. use the official stock Adobe/Magento API for the V1 capability unless a newer
   explicit Product Decision says otherwise.

Research from zero is prohibited when the repository already contains an
implementation, historical implementation, capability inventory, certification
record, or reusable behavior-class evidence for the same capability.

## Standard API is the V1 boundary

Every capability advertised as **standard Magento V1** must operate through the
standard Adobe Commerce / Magento API.

A limitation of the stock API may require:

- bounded/fail-closed behavior;
- read-back/reconciliation;
- explicit merchant remediation;
- a documented residual race or limitation;
- a capability to remain unsupported until real-target evidence exists.

A stock-API limitation does **not**, by itself, authorize replacing the universal
standard connector with a mandatory custom Magento module.

If a required V1 capability appears impossible through the stock Adobe API, the
executor MUST STOP before implementing target-side custom software and raise an
explicit Product Decision containing:

- the exact missing stock capability/invariant;
- primary-source and real-target evidence;
- product impact;
- available moduleless alternatives;
- the operational cost of any proposed target-side component.

No custom Magento component may become a V1 prerequisite without explicit Product
Owner approval recorded as a newer `[Resolved Product Decision]`.

## Product CREATE

Magento V1 Product CREATE uses the standard Adobe/Magento Product API.

For the standard moduleless path, the bounded V1 CREATE sequence is:

```text
fresh SKU read
→ remote missing
→ exactly one standard Product POST
→ exact read-back / reconciliation
→ verify returned logical Product identity + SKU + controlled structural state
→ persist platform-created correspondence only from sufficient non-ambiguous evidence
→ all later runs use the existing linked UPDATE path
```

Rules:

- a remote Product already found under the intended SKU without trusted
  correspondence is never silently adopted;
- no blind Product POST retry after an ambiguous consequential attempt;
- ambiguous/inconclusive creation remains `UnknownOrAmbiguous`;
- ambiguous evidence never mints trusted correspondence;
- successful CREATE correspondence stores the Magento logical Product
  discriminator (`entity_id`) separately from merchant-visible SKU;
- subsequent executions use the existing linked UPDATE path;
- the connector MUST NOT claim that stock Magento provides atomic
  create-if-absent semantics when it does not.

The absence of an atomic stock create-if-absent primitive is a known bounded
limitation of the universal moduleless V1 connector. It is **not** permission to
make a BabyPark Magento module mandatory.

## Configurable Product CREATE — resolved implementation contract

> **[Resolved Product Decision — 2026-09-24]**
>
> Status: **implemented and real-target certified 2026-09-25** on the bounded
> standard moduleless CREATE/resume path.
> Durable evidence:
> `docs/connectors/adobe-commerce/magento_v1_configurable_create_certification_2026_09_25.json`.

Configurable Product CREATE extends the standard moduleless Product CREATE
contract as a **resumable, non-destructive convergence sequence**. It does not
introduce a target-side module, a distributed-transaction fiction, or a new
family/saga persistence table.

The standard-path core order is:

```text
compile complete desired family
→ whole-family read-only preflight before the first write
→ acquire ConnectorAccount operation lock for CREATE/resume core
→ re-check writer/consequential gate + fresh parent/child identity state
→ active Simple children: trusted UPDATE/no-op or certified moduleless CREATE
→ Configurable parent: CREATE disabled or resume trusted platform-created parent
→ configurable options: fresh provider state → no-op / non-destructive PUT / one POST
→ trusted child links: fresh provider state → no-op / one POST
→ fresh option reread + semantic repair after link side effects
→ fresh parent reread verifies create-time bootstrap dimension values are no
  longer ordinary parent custom-attribute state
→ inactive linked-child lifecycle
→ apply final desired parent status only after exact required structure
→ release CREATE/resume core account lock
→ existing Media stage
→ existing Category stage (which acquires its own account-operation lock)
```

### Durable authority and recovery

Durable Product identity checkpoints remain `ExternalRecordLink`:

- active child Product identity uses the Variant subject;
- Configurable parent Product identity uses the Product subject;
- `platform_created` trust is minted only from the same bounded,
  non-ambiguous Product POST + exact reconciliation evidence required by the
  standard Product CREATE contract.

Configurable options and child membership do **not** gain local ownership/saga
tables. Their recovery authority is fresh provider state:

- option semantic identity is trusted parent + configurable attribute;
- child membership semantic identity is trusted parent + trusted child;
- provider-generated option row IDs are remote handles and MUST NOT be treated
  as durable platform identity.

If a remote Product was created but local ERL persistence did not complete, the
next execution MUST NOT recreate or silently adopt it. A fresh remote Product
without trusted correspondence follows the existing Entity Trust/remediation
boundary.

No automatic destructive rollback DELETE is part of production CREATE/resume.
A later failure retains already proven Product checkpoints and resumes from
ERLs plus fresh provider relation reads.

### Preflight before first consequential write

Before child #1 can receive a Product POST, the family path must establish all
deterministically knowable blockers, including:

- complete family semantic compilation;
- currency compatibility;
- intended parent and active-child SKUs;
- local subject/SKU/discriminator correspondence conflicts;
- Attribute Set availability/currentness;
- required CREATE-attribute admission for the actual Product type;
- configurable option/value validity;
- writer/consequential gate state;
- fresh remote parent and active-child existence/identity reads.

After acquiring the account-operation lock, the writer/gate and remote
parent/child identity state are reread before mutation because the read-only
preflight can become stale.

### Configurable parent CREATE admission

The Product CREATE attribute validator is type-aware. A Configurable parent
must be validated for `type_id=configurable`; Simple-only applicability is not
a substitute.

The stock Configurable parent Product POST:

- is sent through standard `POST /V1/products`;
- omits `price`; Magento may materialize parent price as provider state;
- is created with `status=2` (disabled) regardless of a later active desired
  status;
- satisfies all other required Configurable-applicable EAV fields using
  desired product values/defaults where valid.

When a configured dimension itself is required by the merchant Attribute Set
and the parent has no ordinary product-level value for that dimension, the
CREATE envelope may use one deterministic valid value from that dimension's
desired option set as a **create-time bootstrap value**. This bootstrap value:

- is not platform identity or durable desired parent state;
- need not equal the first child value;
- must come from the already validated desired option values;
- must be verified away from ordinary parent custom-attribute state after
  child linking before the parent can be activated.

Real-target probes on 2026-09-24 proved on the certification Magento target
that:

- Configurable parent CREATE succeeds without sending `price`;
- target-specific required EAV values must still be supplied;
- a required configurable-dimension bootstrap value permits Product CREATE;
- the bootstrap value can differ from the first linked child's value;
- Magento removes that parent-level bootstrap custom attribute when the child
  link materializes the Configurable structure.

### CREATE/resume authorization

Configurable option CREATE is exposed only for a trusted
`platform_created` parent in CREATE/resume mode. Existing
`merchant_confirmed` parent families remain on the already certified
UPDATE/relink-only contract unless a later decision explicitly expands them.

A child linked into a platform-created family may use either trusted origin
(`platform_created` or `merchant_confirmed`) when fresh identity/type and
configurable-value evidence are exact. The connector MUST NOT impose a
single-parent ownership rule: stock Magento on the certification target was
verified to allow the same Simple child to belong to more than one
Configurable parent without altering the original family.

### Relation ambiguity rules

For option and child-link POST:

- at most one consequential POST is issued per relation in one execution;
- every attempt is followed by fresh reconciliation;
- exact reconciled state is `KnownApplied` even when the transport result was
  ambiguous;
- unresolved absence after an ambiguous attempt is `UnknownOrAmbiguous` for
  that execution and stops later core writes;
- on a later execution, a new POST is allowed only after a new complete fresh
  provider read again proves the relation absent;
- this is state-conditioned convergence, not a blind retry;
- duplicate/conflicting provider relation state fails closed.

Product POST keeps the stricter Product CREATE rule: an ambiguous Product POST
is never automatically retried merely because a later SKU read is missing or
found.

### Lock boundary

The existing `ConnectorAccountOperationLock` serializes the Configurable
CREATE/resume **core only**. It is released before Media and Category so the
Category executor can acquire its existing identical account lock without
self-deadlock.

The same account-operation lock must protect the standalone moduleless Simple
CREATE core from overlapping CREATE on another `SyncConfiguration` for the
same ConnectorAccount. The lock is not extended across ordinary already-linked
UPDATE execution merely as incidental hardening.

## Safe Sync / first-party Magento component

`B2BPlatform_MagentoSafeSync` and any equivalent first-party Magento-side
component are **OUTSIDE the mandatory standard Magento V1 contract**.

They may exist only as an **optional Enhanced-Safety profile** that is developed,
certified, released, and distributed **separately from standard Magento V1**.

The project intentionally defers further Safe Sync productization until the
standard moduleless Magento V1 connector is complete. Safe Sync may be offered to
a merchant later only when all of the following are true:

- the component is mature and production-ready on its own merits;
- its supported Magento/PHP/edition envelope has been explicitly certified;
- its installation, upgrade, rollback, and support lifecycle are proven;
- its extra safety benefit over the stock REST path is demonstrated with real-target evidence;
- the merchant explicitly chooses the Enhanced-Safety profile.

Safe Sync is therefore **opt-in only**. It must never be silently installed,
implicitly required, or used as a hidden dependency of standard Magento V1.

An optional Safe Sync capability MUST NOT:

- be required to connect Magento V1;
- gate ordinary standard-path connection/readiness;
- be required for standard Product READ;
- be required for standard Product CREATE;
- be required for standard linked Product UPDATE;
- be required for Mapping or Preview;
- silently become a dependency of the normal merchant path;
- replace the standard Adobe API path.

The existence of `integrations/magento-safe-sync` in this repository is therefore
not evidence that Magento V1 depends on it.

## Universality

Magento V1 must contain no BabyPark-specific target assumptions.

Do not hardcode merchant-specific:

- category IDs;
- Attribute Set IDs;
- website/store IDs;
- field codes;
- taxonomy;
- SKU conventions;
- option IDs.

Target structure is discovered from the connected Magento account and adapted
through the platform's generic Connector / Mapping / Classification runtime.

## Historical Stage 3E relationship

Earlier Stage 3E Safe Sync/entity-bound research remains useful as:

- risk analysis;
- optional Enhanced Safety design evidence;
- evidence of limitations in stock Magento semantics.

It is **SUPERSEDED for the standard Magento V1 product path** wherever it states
or implies that Safe Sync, a custom endpoint, an installed first-party Magento
component, or another target-side BabyPark primitive is a prerequisite for
ordinary V1 operation.

When this contract conflicts with an earlier Stage 3E statement about the
**standard V1 path**, this contract wins.

## Acceptance evidence

A standard Magento V1 capability is complete only when:

1. the production-intended path uses the stock Adobe/Magento API;
2. no BabyPark Magento extension is required for that capability;
3. automated tests/CI pass;
4. real-target evidence exists when a real target is available;
5. support/capability truth matches the proven runtime;
6. known stock-API limitations are documented without being hidden behind a
   custom mandatory component.

Unit tests alone do not establish standard Magento V1 completion when a real
Magento target exists.
