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
