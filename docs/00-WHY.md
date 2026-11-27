# 00-WHY.md

# Why This Platform Exists

## Core Belief

We believe businesses should focus on selling products, not on adapting product data to different systems, marketplaces and technical requirements.

We believe a single person should have access to enterprise-grade product management without enterprise complexity.

The platform exists to make professional product management accessible, understandable and useful even for a one-person business.

## The Problem

Most small and growing product businesses manage product information across disconnected tools:

- spreadsheets;

- emails;

- marketplace portals;

- online stores;

- ERP systems;

- manual price lists;

- messengers;

- separate catalogues;

- custom files for customers and partners.

The same product data is copied, edited, reformatted and maintained again and again.

This creates mistakes, wasted time, outdated information and dependency on manual work.

The real problem is not the absence of tools.

The real problem is fragmented product information.

Small businesses often need more than spreadsheets, but they are not ready for the cost, complexity and implementation burden of enterprise systems.

## Our Mission

The platform exists to create one reliable place where product information is maintained once and reused everywhere.

Product data should be created once, updated once and then used across:

- B2B sales channels;

- marketplaces;

- websites;

- feeds;

- APIs;

- SEO content;

- product catalogues;

- internal workflows;

- customer-facing product links;

- future connectors and integrations.

The platform should make product information available wherever the business needs it, without forcing the user to rebuild the same data again.

## First Practical Value

The platform should provide value immediately, even before external integrations are connected.

After registration, each business should receive its own workspace and a native B2B catalogue / sales channel.

The business should be able to:

- add or import products;

- enrich products with descriptions, images, prices and availability;

- publish them in its own customer-facing catalogue;

- share a link with customers;

- receive orders through the platform.

This first native B2B channel is important because it turns product data into a working sales tool immediately.

The platform is not trying to replace a full e-commerce website, marketplace or website builder.

However, if the platform already contains structured product data, images, prices and availability, it should allow the business to use that data for direct B2B sales without building a separate website, marketplace integration or custom B2B portal.

## Company Workspace

Each business should have its own isolated workspace inside the platform.

A workspace represents one company or seller and contains its own:

- products;

- catalogues;

- prices;

- availability;

- customers;

- orders;

- B2B channel settings;

- contact information;

- communication links;

- billing / subscription settings.

The platform should support multiple independent businesses from the beginning.

No company-specific logic should be hardcoded into the core.

Company-specific behavior should be handled through configuration, permissions, mappings, channel settings and reusable platform abstractions.

**Resolved.** Reference clients validate the platform; they do not define the platform. A named customer or pilot may provide smoke evidence, production verification, UX feedback, or real API fixtures. A named customer or pilot must never determine platform capability, Product architecture, catalogue complexity, or connector completeness.

## What Makes This Platform Different

This platform is not intended to become another ERP, CRM, accounting system, marketplace or website builder.

It is a Product Data Platform focused on:

- product information;

- product availability;

- product prices;

- product content;

- product publishing;

- product sales channels;

- product orders created through the platform.

The platform should be powerful enough to support professional product operations, but simple enough for one person to manage without hiring a developer, product manager, content manager, analyst or integration specialist.

The product is designed for businesses that need more than spreadsheets, but do not want the cost, complexity or implementation burden of enterprise systems.

## Connectors and Data Flow

The platform is built around connectors and channels.

Product information may enter the platform through different source connectors:

- manual entry;

- Excel import;

- Google Sheets import;

- CSV import;

- ERP integration;

- API integration;

- supplier feeds;

- future external systems.

Product information may leave the platform through different output channels:

- B2B cabinet;

- marketplace feeds;

- website feeds;

- API;

- product links;

- catalogues;

- SEO content;

- future sales channels and integrations.

Connectors are part of the platform, but no connector should define the core product model.

The core product model must remain independent from any specific source or destination.

Every connector should work through mapping, configuration and reusable platform abstractions instead of custom one-off logic.

## Native B2B Sales Channel

B2B is the first native sales channel of the platform.

It is not only an export connector and not only a mapped feed.

The B2B channel should allow a business to sell products directly through the platform using the same core product data, prices, availability and customer rules.

The initial B2B capability should support:

- customer access to a product catalogue;

- product cards based on the core product model;

- customer-specific or contract prices;

- product availability;

- order creation;

- order transfer to the platform or connected external systems;

- future reservation logic where applicable.

B2B must use the shared platform core.

It must not create a separate product model, separate price model or separate availability model only for B2B.

B2B-specific behavior should be implemented through channel configuration, customer rules, pricing rules and permissions.

The goal is that even a one-person business can publish products, share access with customers and receive orders without building a separate website, marketplace integration or custom B2B portal.

## Standards and Familiarity

The platform should prefer established standards and familiar mental models whenever they provide a good solution.

Before introducing custom terminology, field names, identifiers, formats, workflows or APIs, the platform should check whether an authoritative standard or widely accepted industry practice already exists.

Examples of preferred sources include:

- GS1 for global product identifiers and barcode-related concepts;

- Google Merchant product attributes for widely used product-feed terminology;

- schema.org Product vocabulary for structured product data;

- ISO standards for currencies, countries, units and similar universal values;

- widely adopted e-commerce and PIM practices where no formal standard exists.

Custom concepts are allowed when no suitable standard exists, especially for B2B, wholesale, product availability, pricing rules and internal workflows.

When a custom concept is introduced, it must be documented explicitly.

The goal is not to copy any existing product.

The goal is to reuse proven standards and familiar concepts so that the platform feels understandable without unnecessary explanation.

Product examples may inspire UX or product thinking, but they must not be copied as architecture.

## Accessibility

Professional product management should not be available only to large companies.

A small seller, a one-person business or a growing company should be able to manage products with confidence.

The platform should provide enterprise-grade capabilities with consumer-grade simplicity.

A business should be able to start with basic product management and later unlock more advanced capabilities without moving to a different system.

The platform should grow together with the customer.

## Simplicity

Simplicity is a product requirement, not a temporary limitation.

Every new feature must make the platform more capable without making it feel more complicated for the majority of users.

Whenever possible, the platform should:

- automate repetitive work;

- reduce manual copying;

- reduce the number of decisions required from the user;

- use familiar terminology;

- rely on established standards;

- make the correct action obvious;

- avoid unnecessary configuration;

- avoid forcing users to understand technical details.

Automation is preferred over manual repetition when it does not reduce transparency, correctness or user control.

If a new feature can simplify future work through safe automation, mapping, defaults, templates or reusable configuration, that option should be preferred.

## Source of Truth

Product information should have one trusted place.

Other systems may provide data through source connectors.

Other systems may receive data through output connectors and sales channels.

But the platform should keep product information structured, understandable and reusable.

The platform must not depend on one specific source such as ERP, Excel, Google Sheets, CSV, API or manual entry.

Any of them may be the starting point.

The platform core must be connector-independent.

Connectors should adapt external systems to the platform, not force the platform to adapt its core model to every external system.

## Sales Channels

Sales channels are ways to use product data commercially.

The same product data should be usable for:

- B2B cabinet;

- marketplace feeds;

- API;

- product links;

- websites;

- SEO;

- future connectors.

B2B is the first native sales channel because it can provide immediate value without waiting for external integrations.

External sales channels should be connected through mapping, configuration and reusable connectors.

Adding a new sales channel should be a configuration and mapping task, not a custom development project.

## Long-Term Goal

We are building a Product Data Platform that enables even a one-person business to manage product information once and confidently use it everywhere.

The platform should help a business start simple, sell immediately through its native B2B channel, and later connect more channels, integrations and advanced capabilities without replacing the system.

The long-term idea is simple:

Manage your product once. Use it everywhere.