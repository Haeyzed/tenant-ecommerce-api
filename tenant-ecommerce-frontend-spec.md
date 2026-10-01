# Multi-Tenant E-Commerce Platform: Frontend Architecture Specification

| | |
|---|---|
| **Document** | `tenant-ecommerce-frontend-spec.md` |
| **Version** | 2.0. This version supersedes the draft split across `fe-01.md` to `fe-05.md` (version 1.0). |
| **Status** | Authoritative frontend blueprint. It is ready for implementation. |
| **Backend contract** | The implemented Laravel API in `tenant-ecommerce-api`, its specification `tenant-ecommerce-api-spec.md` v3.3 (cited as "API §n", decisions as "D-n"), and the Scramble documents `docs/api/landlord.openapi.json` and `docs/api/tenant.openapi.json` |
| **Frontend repository** | `tenant-ecommerce-frontend`: a pnpm and Turborepo monorepo |
| **Audience** | Frontend engineers, coding agents, the backend engineers who own the contract, and platform operations |

---

## Table of Contents

**Part I: Foundations**
- [0. How to Use This Document](#0-how-to-use-this-document)
- [1. Purpose, Scope and Principles](#1-purpose-scope-and-principles)
- [2. Reconciliation with Previous Work](#2-reconciliation-with-previous-work)
- [3. Verified Backend Contract](#3-verified-backend-contract)
- [4. Technology Stack](#4-technology-stack)
- [5. Monorepo and Packages](#5-monorepo-and-packages)

**Part II: Platform Plumbing**
- [6. Applications and Hosts](#6-applications-and-hosts)
- [7. Edge Routing and Tenant Resolution](#7-edge-routing-and-tenant-resolution)
- [8. Backend-for-Frontend Layer](#8-backend-for-frontend-layer)
- [9. Authentication](#9-authentication)
- [10. Authorisation and the Access Snapshot](#10-authorisation-and-the-access-snapshot)
- [11. Modules, Limits, Tenant Status and Subscription UX](#11-modules-limits-tenant-status-and-subscription-ux)
- [12. API Client](#12-api-client)
- [13. API Contract and Type Generation](#13-api-contract-and-type-generation)
- [14. State Management, Data Fetching and Caching](#14-state-management-data-fetching-and-caching)
- [15. Routing and URL State](#15-routing-and-url-state)

**Part III: Shared Application Architecture**
- [16. Feature Architecture Inside an App](#16-feature-architecture-inside-an-app)
- [17. Admin Shell and Navigation Registry](#17-admin-shell-and-navigation-registry)
- [18. CRUD, Data Tables, Forms and Custom Fields](#18-crud-data-tables-forms-and-custom-fields)
- [19. Bulk Actions, Imports, Exports and Asynchronous Operations](#19-bulk-actions-imports-exports-and-asynchronous-operations)
- [20. Files and Media](#20-files-and-media)
- [21. Notifications and Realtime](#21-notifications-and-realtime)
- [22. Dashboards and Metrics](#22-dashboards-and-metrics)
- [23. Design System and Theming](#23-design-system-and-theming)

**Part IV: The Applications**
- [24. platform-web](#24-platform-web)
- [25. platform-admin](#25-platform-admin)
- [26. affiliate-portal](#26-affiliate-portal)
- [27. tenant-admin](#27-tenant-admin)
- [28. Seller Portal](#28-seller-portal)
- [29. storefront](#29-storefront)

**Part V: Cross-Cutting Requirements**
- [30. Error and UX State System](#30-error-and-ux-state-system)
- [31. Security](#31-security)
- [32. Performance](#32-performance)
- [33. Accessibility](#33-accessibility)
- [34. Internationalisation and Formatting](#34-internationalisation-and-formatting)
- [35. Testing Strategy](#35-testing-strategy)
- [36. Observability](#36-observability)
- [37. Configuration and Environment Variables](#37-configuration-and-environment-variables)
- [38. Build, CI, Deployment and Local Development](#38-build-ci-deployment-and-local-development)

**Part VI: Maps, Gaps and Plan**
- [39. Repository Tree](#39-repository-tree)
- [40. Module Mapping and API Coverage Matrix](#40-module-mapping-and-api-coverage-matrix)
- [41. Backend Contract Gaps](#41-backend-contract-gaps)
- [42. Implementation Phases](#42-implementation-phases)
- [43. Non-Goals](#43-non-goals)
- [44. Architectural Decisions](#44-architectural-decisions)
- [45. Consistency Audit](#45-consistency-audit)

---

# Part I: Foundations

## 0. How to Use This Document

- **Normative language.** MUST, MUST NOT, SHOULD and MAY have their RFC 2119 meanings. Everything else is explanation.
- **The backend is authoritative for behaviour.** This document restates backend facts only where they constrain the frontend, and cites the source. The facts in §3 were verified against the running code, not only the API specification. If the backend changes, §3 and the affected sections are corrected in the same pull request as the frontend change.
- **Gaps are named, never assumed.** A capability that the frontend needs but the backend does not provide is a backend contract gap, numbered `BG-nn` and listed in §41. The frontend never invents an endpoint. Where a feature depends on a gap, this document states the interim behaviour that works with today's API.
- **Placeholders.** `ROOT` is the value of the backend's `PLATFORM_ROOT_DOMAIN` (for example `shopfront.io`). `{slug}` is `tenants.slug`. These are configuration values, not unresolved design.
- **Section references.** Sections of this document are written "§n". Backend spec sections are "API §n". Backend decisions are "D-n".
- **Coding agents** implement the phases of §42 in order. A phase is complete only when its tests (§35) and boundary checks (§5.6) pass and its acceptance criteria hold.

---

## 1. Purpose, Scope and Principles

### 1.1 Purpose

This document defines every web frontend that talks to the Laravel API. For each application it covers:

- what the application is and where it runs;
- how it resolves the tenant, authenticates and authorises;
- how it reads module state, fetches and caches data, and renders;
- how it is organised, tested, built and deployed;
- which backend contract changes it needs.

### 1.2 In Scope

| Surface | Actor (API §10.1) | Application |
|---|---|---|
| Marketing website, pricing, legal pages, platform blog, tenant signup, OAuth relay | Anonymous visitors, registrants | `platform-web` |
| Platform (landlord) administration | Platform users | `platform-admin` |
| Affiliate programme portal | Affiliates | `affiliate-portal` |
| Tenant back office, point of sale, kitchen display | Tenant staff | `tenant-admin` |
| Marketplace seller portal | Sellers | `tenant-admin` (separate shell and session) |
| Storefront, customer account, guest checkout | Customers, guests | `storefront` |

### 1.3 Out of Scope

- **The driver app.** Drivers sign in with phone, PIN and OTP (`/api/driver/*`) and work on mobile devices. The API is ready for a native or PWA client. Building it is a separate product decision (§43).
- **Native mobile apps.** The public `/api` on every host and the BFF design (§8) keep them possible.
- **Kitchen and POS hardware integration** beyond browser routes, keyboard-wedge scanners and `window.print()` (§27.6).

### 1.4 Principles

1. **The API is the security boundary.** The frontend hides what a user cannot do, for usability. It never decides what a user may do. Every guard in the frontend has a matching backend check, and every refusal from the backend is handled gracefully, even when the frontend's own guard said yes.
2. **One module system, one permission system.** Module keys, states, permissions and limits come from the backend. The frontend reads them. It never defines its own module list, entitlement rule or state machine (§11).
3. **The backend's contract as it is.** The frontend consumes the real response envelope (§3.3) and normalises failures into one internal error type (§12.4). Where the contract is incomplete, the gap is recorded in §41. The frontend never builds a workaround that assumes the gap is fixed.
4. **Server state lives in server-state caches.** TanStack Query holds it on the client and the Next.js cache on the server. No global client store holds API data (§14).
5. **The URL is state.** Anything a user would bookmark, share or expect Back to restore is in the URL (§15).
6. **Tokens never reach browser JavaScript.** Sanctum tokens live in encrypted HttpOnly cookies, and the application's own server attaches them (§8).
7. **Tenant identity comes from the host and only the host.** It never comes from a header the client controls, a query parameter, a cookie or client state (§7).
8. **Small, explicit packages.** A shared package exists only when two applications use it, or when it isolates a hard boundary, and it has one clear responsibility (§5.3).
9. **Boring by default.** Every dependency is justified in §4. An addition needs an ADR (§44).

---

## 2. Reconciliation with Previous Work

### 2.1 Inputs Reviewed

| Input | What was taken from it |
|---|---|
| Backend code (`tenant-ecommerce-api`): 1,238 routes, middleware groups, exception renderer, `APIResponse`, auth services, tenancy resolver, CORS, broadcast channels, frontend-link builder (`FrontendUrl`), module and limit registries, storefront config, import, export, bulk and social-login services | The facts in §3. Wherever the code and the specification disagree, the code wins. |
| `tenant-ecommerce-api-spec.md` v3.3, including Appendix D up to D-140 | Domain rules, lifecycles, module semantics and decision history |
| `docs/api/landlord.openapi.json` (232 operations) and `docs/api/tenant.openapi.json` (996 operations), OpenAPI 3.1 | Operation IDs, parameters, request bodies. Response-schema quality was also measured (§3.9). |
| `fe-01.md` to `fe-05.md` (frontend draft 1.0, sections 1 to 34 of a planned 50) and the prompt that produced them | The architecture baseline. Every decision was revalidated against the items above. |
| `tenant-ecommerce-frontend` repository | Real versions, packages and scaffolding (§5.1) |

The draft ended at its own section 34. Its sections 35 to 50 (security, performance, accessibility, i18n, testing, observability, configuration, CI, tooling, repository tree, module map, backend adjustments, build order, non-goals, ADRs, audit) were never written. This document supplies them.

### 2.2 Reconciliation Matrix

Classification: **A** kept as correct, **B** outdated and updated, **C** incomplete and completed, **D** backend changed and frontend adapted, **E** backend spec and code differ, **F** the frontend needs something with no backend support (gap), **G** a backend capability with no frontend strategy (added).

| Area | Draft decision | Verified reality | Class | Final decision |
|---|---|---|---|---|
| Response envelope | `{data}`, `{data, links, meta}`, `{error, message, details}`. The draft rejected a `success` envelope. | Every response is `{success, message, data, meta, errors}` (UD-40). Pagination is in `meta.pagination` and `meta.links`. Errors carry `meta.error_code` and `meta.details`, with field errors in `errors`. | B, D | The client unwraps the real envelope (§3.3, §12). |
| Tenant identification by the BFF | Send the tenant host as `X-Forwarded-Host`. | Laravel trusts no proxies and resolves the tenant from the raw `Host` header, for landlord routes too. | D | The BFF sends `Host: {apiHost}` to the private Laravel address (§7.4, §8.3). |
| Client IP forwarding | Assumed Laravel trusts forwarded headers (BA-03). | Laravel trusted no proxies, so every BFF request would have shared one IP for the `public` (60/min) and `auth-sensitive` (5/min) limiters. | F | Resolved: BG-01 (D-141), with `TRUSTED_PROXIES` trusting only `X-Forwarded-For` and `-Proto`. |
| Tenant-admin origin | `{slug}.admin.ROOT` | The backend generated tenant-admin links as `{primary domain}/admin/...`. | E, F | Keep the separate origin for security (ADR-07). Resolved: BG-02 (D-141). The backend builds admin and seller links from `TENANT_ADMIN_URL`. |
| Other hosts | `console.ROOT`, `ws.ROOT`, `media.ROOT` | The backend reserves `platform`, `affiliates`, `cdn` and `admin`, but not `console`, `ws` or `media`. | B | `platform.ROOT`, `affiliates.ROOT`, `cdn.ROOT`, and Reverb on `ROOT/app`. No new reserved slug is needed (§6.2). |
| Affiliates | Absent | A sixth actor with its own guard, portal routes (`/api/affiliate/*`), portal URL config and link emails (API §21A, UD-41) | G | New app `affiliate-portal` (§26). |
| Platform-user session | No `me`, no refresh, no password change (BA-01) | `GET /api/admin/auth/me` now exists. There is still no refresh and no password change. | D, F | Use `me`. Refresh and password change are BG-10. |
| Login response | `expires_at` missing (BA-11) | Every login returns `{token, token_type, expires_at, user}`. | D | Seal `expires_at` in the session (§8.2). |
| Social login | Absent | Google and Facebook through a platform-wide relay page, single-use state and link or unlink (D-132) | G | A full flow, with the relay hosted in `platform-web` (§9.5). |
| Guest token | Returned in the `X-Guest-Token` response header | Returned in the body as `data.guest_token` on cart responses | D | The BFF removes it from the body and seals it in a cookie (§8.4). |
| Frontend links in emails | Unknown targets (BA-02) | `FrontendUrl` defines every path (§3.8). | D | Frontend routes match these paths exactly. |
| Registration verification link | `platform-web` | The backend sent it to `PLATFORM_ADMIN_URL/register/verify`. | E, F | Resolved: BG-03 (D-141). The link now uses `PLATFORM_WEBSITE_URL`. |
| Payment return | `/checkout/return?reference=` | The gateway callback is `{storefront}/orders/{order}/payment-return`. | D | The route is `/orders/[order]/payment-return` (§29.7). |
| Product and category by slug | Missing (BA-14) | `{product}`, `{category}` and `{brand}` accept an id or a slug. | D | Use slugs directly. |
| Bulk actions | Products and coupons only, synchronous | Products, coupons, customers, categories, orders and reviews. Synchronous, at most 100 ids, with a summary `{operation_id, succeeded, failed, results}` (D-133) | D | One bulk bar with a per-item result dialog (§19.1). |
| Imports | Absent | Queued imports with types, templates, statuses, error rows and notification (D-135) | G | Reusable import flow (§19.2). |
| Exports | Tenant exports only | Tenant and platform exports in csv, xlsx and json, plus report pdf; queued, notified, 7-day retention (D-134) | C, D | Shared export centre in both admin apps (§19.3). |
| Notifications | Polling; no unread count (BA-17); no landlord inbox | Staff, customer and affiliate inboxes with `meta.unread_count`. Platform messages reach the staff inbox (D-137). There is no platform-user inbox and no mark-all-read. | D, F | Bell with count (§21.1). Gaps BG-08 and BG-09. |
| OpenAPI types | Generated types as the source of truth for everything | Operation IDs equal route names, and parameters and request bodies are typed. Response `data` was typed as `string` in 95% of operations, and documented errors used Laravel's default shape. | D, F | Resolved in D-142 (BG-06): generated response types, with a small overlay for the remaining untyped operations (§13). |
| Route manifest (permission and module per route) | Contract bundle (BA-06) | Was not produced by the backend | F | Resolved in D-142 (BG-07): `php artisan frontend:contract` (§13.3, §13.4). |
| Realtime | Support conversations; channel name from the API | Channels need the tenant id. Auth routes are `/api/broadcasting/auth` and, for guest support, `/api/support/broadcasting/auth`. | C, D | The tenant id is now in the storefront config and staff `me` (BG-04, D-141). Channel names are built from it (§21.2). |
| Dashboards | Widgets poll separately | Section endpoints `/api/admin/dashboard/{section}` and per-resource `/metrics` return KPI shapes (UD-39). | C | One request per section (§22). |
| Onboarding checklist | Endpoint assumed | `GET /api/admin/onboarding` exists (D-136). | D | Owner dashboard card (§27.3). |
| HR shifts, overtime, commission, FX | Absent | D-137 to D-140 | G | Mapped in §27 and §40. |
| Storefront config | Missing identity and module flags (BA-07) | Has business, formatting, checkout, module flags, social providers, announcement bar and `tenant: {id, slug, primary_domain}` | D | Resolved: BG-04 and BG-11 (D-141) |
| Store images | Media endpoints missing (BA-05) | Product, brand, category, CMS and seller product media routes exist. Store logo, favicon, share image and variant image have no upload route. | D, F | Gap BG-12, resolved in D-142. |
| Monorepo | pnpm, Turborepo, `@repo/*` names | The repo exists: pnpm 10.33, Turborepo 2.9, `@workspace/*` names, four apps, Next.js 16.3.3, React 19.2.4, Base UI shadcn style `base-nova`, HugeIcons | A, B | Keep the repo and its naming. Add packages per §5. |
| Technology choices | Next.js, Tailwind, shadcn on Base UI, TanStack Query and Table, nuqs, RHF, Zod, `openapi-fetch`, no Axios | Confirmed by the repo, apart from missing packages | A | Kept (§4). |
| Security, testing, CI, deployment, observability | Referenced but unwritten (draft sections 35 to 50) | Not applicable | C | Written in Parts V and VI. |

---

## 3. Verified Backend Contract

This section states only facts the frontend depends on. Each was checked in the code.

### 3.1 Hosts and Tenancy

| Fact | Evidence |
|---|---|
| Landlord routes are bound with `Route::domain('{landlord_domain}')` to `tenancy.central_domains` (the root domain plus `CENTRAL_DOMAINS`). Every other host goes to the tenant routes. | `bootstrap/app.php` |
| The tenant is resolved from `$request->getHost()` by `InitializeTenancyByDomain` through `HostTenantResolver`. It matches `domains.domain` exactly, and only for domains that identify a tenant (subdomains, and verified custom domains of active tenants). | `HostTenantResolver` |
| An unknown host returns 404 `not_found`. | `ExceptionRenderer` |
| Trusted proxies come from `TRUSTED_PROXIES` (D-141). Only `X-Forwarded-For` and `X-Forwarded-Proto` are trusted, so `ip()` is the real client behind the edge and BFF, while `getHost()` is always the raw `Host`. | `App\Shared\Http\TrustedProxies` |
| Reserved slugs: `www api app admin mail smtp ftp static assets cdn docs help support status blog affiliate affiliates billing login register dashboard platform root system test` | `TenantRegistrationService::RESERVED_SLUGS` |
| CORS (`HandleDynamicCors`) allows the platform admin origins, https origins on landlord domains, tenant subdomains and verified custom domains. It never uses a wildcard and does not support credentials, because every actor uses bearer tokens. | `HandleDynamicCors` |

### 3.2 Actors and Authentication

All actors use Sanctum personal access tokens carrying one ability each, and `auth.as:{actor}` rejects every other actor. The default lifetime is 30 days (`SANCTUM_EXPIRATION`, one value for all actors). The token prefix is `tea_`.

| Actor | Login | Session endpoints |
|---|---|---|
| Platform user (landlord) | `POST /api/admin/auth/login` | `GET /api/admin/auth/me`; `POST logout`; `PATCH preferences`; email verify and resend; password forgot and reset. No refresh, no password change. |
| Affiliate (landlord) | `POST /api/affiliate/auth/login` | `POST apply`; logout; email verify and resend; password forgot, reset and change (`PATCH`). Profile at `GET /api/affiliate/profile`. |
| Staff (tenant) | `POST /api/admin/auth/login` | `GET /api/admin/auth/me`, `POST refresh`, logout, password forgot, reset and change, preferences |
| Customer (tenant) | `POST /api/auth/login`; social login (§9.5) | `POST register` (merges the guest cart), logout, email verify and resend, password forgot, reset, change and `set`. Profile at `GET /api/account`. |
| Seller (tenant, `marketplace`) | `POST /api/seller/auth/login` | register (starts `pending`), logout, password forgot, reset and change. Profile at `GET /api/seller/profile`. |
| Driver (tenant) | `POST /api/driver/auth/login`, `request-otp`, `verify-otp` | Out of scope (§1.3) |

Login and refresh return `data: {token, token_type, expires_at, user}`.

The staff `me` returns:

- `user`, `roles`, `permissions` (the effective names);
- `is_owner`;
- `modules` (key to state);
- `display` (timezone, date and time format);
- `payment_mode`.

For the owner only it adds `tenant_status`, `subscription_status` and `pending_legal_documents`. The platform `me` returns `user`, `roles`, `permissions` and `display`.

### 3.3 Response Envelope

Every JSON response, success or failure, has this shape (`APIResponse`, UD-40):

```json
{
  "success": true,
  "message": "OK",
  "data": {},
  "meta": {},
  "errors": {}
}
```

| Case | Shape |
|---|---|
| One resource | `data` is the object. |
| Length-aware list | `data` is an array. `meta.pagination = {current_page, per_page, from, to, total, last_page}` and `meta.links = {first, prev, next, last}`. |
| Cursor list | `meta.pagination = {per_page, next_cursor, prev_cursor}` and `meta.links = {next, prev}` |
| Extra list meta | Merged into `meta`, for example `meta.unread_count` on inboxes and `meta.outstanding` on commissions |
| Created, accepted | 201 and 202 with the same envelope. Imports and exports return 202. |
| Error | `success: false`, `data: null`, `meta: {error_code, details}`, and `errors` holding field errors for 422 |
| Money | A decimal string with 4 decimals (`"1250.5000"`) next to a currency code |
| Quantity | A decimal string with 3 decimals |
| Date-time | ISO 8601 with offset. Date-only values are `YYYY-MM-DD`. |

Framework error codes (`ExceptionRenderer`):

| Status | Code |
|---|---|
| 401 | `unauthenticated` |
| 404 | `not_found`, which includes an unknown tenant host |
| 405 | `method_not_allowed` |
| 422 | `validation_failed` |
| 429 | `too_many_requests` |
| 500 | `server_error` |

Domain codes are listed where they are used (§11, §19, §29, §30).

### 3.4 Access Model

The middleware order on tenant admin routes is fixed:

1. request context;
2. tenancy by host;
3. platform maintenance (503 `maintenance`);
4. tenant status;
5. throttles (`tenant`, `api`);
6. `auth.as:staff`;
7. per route: feature, module notice, usage limit and permission;
8. derived permission.

| Signal | Status and code | `details` |
|---|---|---|
| Tenant awaiting payment | 402 `subscription_payment_required` | none |
| Tenant provisioning or provisioning failed | 503 `tenant_provisioning` | none |
| Tenant suspended | 403 `tenant_suspended` | none |
| Tenant closed or purged | 410 `tenant_closed` | none |
| Past due, read-only (admin writes, except `/api/admin/billing/*`) | 402 `subscription_past_due` | `{restriction}` |
| Past due, blocked (also storefront, customer, seller and driver routes) | 402 `subscription_past_due` | `{restriction}` |
| Module not accessible | 403, code from the module state: `feature_unavailable`, `module_disabled`, `module_locked` or `module_suspended` | `{module, state, entitled_by_plans?, reason?}` |
| Module maintenance notice | 503 `maintenance`, and `X-Module-Notice: {id}` on responses | as sent |
| Usage limit | 403 `limit_reached` | `{limit, used, limit_value}` |
| Permission | 403 `forbidden` | `{permission}` |
| Record scope | 404 `not_found` | none |
| Idempotency | 422 when the key is missing or invalid; 409 `idempotency_conflict`; 409 `idempotency_in_progress` | none |

Module states are `unavailable`, `available`, `enabled`, `disabled`, `locked` and `suspended`. `disabled` and `locked` still allow admin `GET` requests on read-when-inactive modules and on wind-down routes.

### 3.5 Rate Limits

| Limiter | Where | Limit |
|---|---|---|
| `api` | Authenticated groups | 120 per minute per token |
| `public` | Public groups, storefront | 60 per minute per IP |
| `auth-sensitive` | Login, register, forgot and reset, resend, OTP, gift-card balance, coupon validation, job applications, registration, contact | 5 per minute per IP plus the submitted identifier |
| `tenant` | Every tenant route | `max_api_requests_per_minute` per tenant |

A 429 response carries `Retry-After`.

### 3.6 Modules and Limits

- **Module keys** (`config/modules.php`, with their class):
  - `pos`, `purchasing`, `accounting`, `expenses`, `hr`, `marketplace`, `gift_cards`, `reward_points`, `sales_quotations`, `sales_agents`, `product_subscriptions`, `support`, `ai_assistant` and `project_management` are modules;
  - `hr_payroll` and `hr_recruitment` are submodules;
  - `installments`, `multi_currency`, `back_in_stock_alerts`, `approval_workflows`, `advanced_reporting`, `content_marketing` and `custom_email` are capabilities;
  - `manufacturing`, `restaurant`, `booking` and `repair` are verticals;
  - `woocommerce`, `social_commerce` and `whatsapp` are integrations.
- **Limit keys** (`config/limits.php`): `max_users`, `max_products`, `max_warehouses`, `max_orders_per_month`, `max_storage_mb`, `max_pos_registers`, `max_custom_domains`, `max_custom_fields`, `max_employees`, `max_sellers`, `max_api_requests_per_minute`.
- **Storefront-visible module flags** (`GET /api/storefront/config` `modules`): `reward_points`, `gift_cards`, `multi_currency`, `back_in_stock_alerts`, `installments`, `booking`, `content_marketing`.

### 3.7 Realtime

- The backend runs Reverb, with the Pusher protocol.
- Broadcast events:
  - `SupportMessageSent` and `SupportConversationStatusChanged` on `private-tenant.{tenantId}.support-conversation.{id}`;
  - staff channels `tenant.{tenantId}.support-inbox` and presence `tenant.{tenantId}.support-agents-online`;
  - `PlatformSupportMessageSent` and `PlatformSupportConversationStatusChanged` on `platform-support-conversation.{id}`.
- **Auth endpoints:**
  - landlord `POST /api/broadcasting/auth` (platform users);
  - tenant `POST /api/broadcasting/auth` (staff and customers);
  - tenant `POST /api/support/broadcasting/auth` (guests, with their guest token).
- Nothing else broadcasts: no notifications, orders, stock, imports, exports, POS or kitchen events.

### 3.8 Frontend Links Generated by the Backend

The backend places these URLs in emails and gateway callbacks (`FrontendUrl`). The frontend MUST serve exactly these paths.

| Base | Path | Purpose |
|---|---|---|
| `PLATFORM_ADMIN_URL` | `/reset-password?token&email`, `/verify-email?…`, `/exports/{id}` | Platform user password set and reset, verification, export ready |
| `PLATFORM_WEBSITE_URL` | `/register/verify?registration&code` | Registration verification (D-141) |
| `AFFILIATE_PORTAL_URL` | `/reset-password`, `/verify-email` | Affiliate auth |
| `PLATFORM_WEBSITE_URL` | `/?ref={code}`, and the landlord sitemap paths | Referral links, sitemap |
| Tenant primary domain | `/reset-password`, `/verify-email`, `/account/downloads`, `/account/export?link=`, `/account/subscriptions/{id}`, `/account/quotations/{id}`, `/products/{slug}`, `/orders/{order}/payment-return`, and the CMS paths `/`, `/pages/{slug}`, `/blog`, `/blog/{slug}`, `/products/{slug}`, `/categories/{slug}`, `/brands/{slug}` (`config/cms.php` `paths`) | Customer flows, sitemap and menus |
| `TENANT_ADMIN_URL` (`https://{slug}.admin.{root}`) + `/seller` | `/reset-password` | Seller reset (D-141) |
| `TENANT_ADMIN_URL` (`https://{slug}.admin.{root}`) | `/`, `/reset-password`, `/exports/{id}`, `/imports/{id}`, `/billing/callback?reference=` | Staff links (D-141) |

### 3.9 OpenAPI Documents

`php artisan api-docs:export` writes both documents.

- **Operation IDs:** every operation has one, and it equals the Laravel route name (for example `tenant.catalog.admin.products.index`).
- **Paths:** relative to the server URL `http://{host}/api`.
- **Request side:** parameters, enums and request bodies are well typed.
- **Response data:** typed as `{"type": "string"}` in 211 of 232 landlord and 916 of 996 tenant operations. No operation has an object schema for `data`.
- **Errors:** documented with Laravel's default shape (`{message, errors}`), not the real envelope.

§13 draws the consequences.

---

## 4. Technology Stack

Versions are pinned exactly in each `package.json`, and shared runtime versions are enforced with `pnpm.overrides` at the root. The repository today pins Next.js 16.3.3 and React 19.2.4.

| Technology | Verdict | Where | Why | Trade-off accepted |
|---|---|---|---|---|
| **Next.js 16, App Router** | Keep | All five apps | Server Components give fast, crawlable first paint for the storefront and website. Route handlers host the BFF. Cache Components (`cacheComponents`, `'use cache'`, `cacheLife`, `cacheTag`) cache per-tenant storefront data. `proxy.ts` (formerly middleware) handles host checks. | Framework churn. Mitigated by exact pins and reading `node_modules/next/dist/docs` before using an API (repo `AGENTS.md`). |
| **React 19.2** | Keep | All apps | Required by Next 16. Suspense streaming for personal islands. | None |
| **TypeScript 5, strict** | Keep | Everywhere | `strict` and `noUncheckedIndexedAccess` are already on in `typescript-config/base.json`. Add `exactOptionalPropertyTypes` in new packages. | Slower initial writing |
| **Tailwind CSS v4** | Keep | Everywhere | CSS-first `@theme` tokens map to runtime tenant themes through CSS variables (§23.3). | Utility-heavy markup, contained by `@workspace/ui` |
| **shadcn/ui (style `base-nova`) on Base UI** | Keep | `packages/ui` | Components are owned code on one primitive layer (`@base-ui/react`). RTL support is on in `components.json`. | The team maintains copied code. |
| **HugeIcons** | Keep, behind a facade | `packages/ui/icons` | Already configured as the shadcn icon library. One facade file makes a later switch trivial. | Smaller ecosystem than Lucide |
| **TanStack Query v5** | Add | Admin apps, affiliate portal, storefront client islands | Client server-state cache with invalidation, retries, optimistic updates and SSR hydration | A second cache beside the Next cache; their responsibilities are split in §14. |
| **TanStack Table** | Keep (already in `packages/ui`), move | `packages/admin-kit` | Headless server-driven tables. The dependency moves out of `ui`, which must hold no data concerns. | Verbose column definitions, wrapped by `DataTable` |
| **nuqs v2** | Keep | All apps with lists or filters | Typed URL state, with parsers shared by server (`createSearchParamsCache`) and client | None significant |
| **React Hook Form** | Add | All forms | Uncontrolled inputs keep large forms fast, and it pairs with the shadcn `Field` components already in `ui`. | TanStack Form considered; less mature for dynamic forms |
| **Zod v4** | Keep (already in `ui`), move | Forms, env validation, BFF input validation | Validates untrusted input at the edges | Not used to mirror backend rules (§18.4) |
| **openapi-typescript + openapi-fetch** | Add | `packages/contract`, `packages/api-client` | Typed paths, operation parameters and bodies from Scramble, over native `fetch` with middleware | Response types are generated since D-142; a small overlay covers the untyped rest (§13) |
| **Native `fetch`** | Keep | Everywhere | Streams, `AbortSignal`, and Next data-cache integration on the server | No upload progress; `XMLHttpRequest` is used for that one case (§20.1) |
| **jose** | Add | `packages/bff` | JWE session cookies (`dir` + `A256GCM`) | None |
| **laravel-echo + pusher-js** | Add | `packages/realtime` | The Reverb client protocol | Loaded only on realtime routes |
| **Tiptap** | Add | CMS and blog editors in both admin apps | Rich text limited to the allowed HTML subset (§23.5) | Bundle size, loaded only on editor routes |
| **sanitize-html** | Add | `packages/cms-render`, server only | Allow-list sanitisation of rich text | None |
| **Recharts 3** | Keep (already in `ui`) | Dashboards and reports | shadcn `chart` wrapper with tokens | Client-only, loaded per route |
| **next-intl** | Add | `storefront`, `platform-web` | Customer-facing strings, locales and RTL (§34) | Admin apps are English-only in v1 |
| **next-themes** | Keep | Admin apps, affiliate portal | Already wired in each app's `ThemeProvider` for light and dark | Not used on the storefront, which uses tenant tokens |
| **date-fns 4** | Keep | `packages/format` | Tree-shakeable, with timezone support through `@date-fns/tz` | None |
| **Zustand** | Optional, POS only | `tenant-admin` POS | The only genuinely client-owned state that must survive reloads (§27.6) | Allowed nowhere else |
| **Vitest, Testing Library, MSW, Playwright, axe-core** | Add | §35 | Standard, fast, and they work with Server Components and end-to-end tests | None |
| **`cn` npm package** | Replace | `packages/ui/src/lib/utils.ts` | `cn` currently re-exports an unrelated package named `cn`, not the shadcn standard. It is replaced by `clsx` + `tailwind-merge`, so conflicting Tailwind classes merge correctly and the dependency is a known one. | None |
| **`next/font/google`** | Keep for admin apps | Admin apps | Fonts are downloaded at build time and self-hosted by `next/font`. | The storefront uses `next/font/local` for tenant fonts (§23.3). |
| **Axios** | Remove | Nowhere | `fetch` + `openapi-fetch` covers interceptors, typing and streaming. | None |
| **Redux, MobX, a global Zustand store** | Remove | Nowhere | §14 | None |
| **Radix UI** | Remove | Nowhere | Base UI is the single primitive layer. Community Radix blocks are ported with the `migrate-radix-to-base` skill already in the repo. | Porting effort |
| **Auth.js / NextAuth** | Remove | Nowhere | Laravel issues and revokes Sanctum tokens. Auth.js would add a second session system with its own callbacks and adapters to hold a token it cannot manage. The BFF's sealed cookie (§8.2) is ~150 lines and fits exactly. | Owned session code |
| **Server Actions** | Not used | Nowhere | One mutation path (typed client through the BFF) keeps idempotency, request IDs, error normalisation and TanStack invalidation uniform. | Slightly more code for trivial forms |

---

## 5. Monorepo and Packages

### 5.1 The Existing Repository

`tenant-ecommerce-frontend` already provides:

- pnpm 10.33 workspaces (`apps/*`, `packages/*`) and Turborepo 2.9 with `build`, `dev`, `lint`, `format` and `typecheck` tasks;
- four apps from the shadcn monorepo template: `platform-admin` (dev port 3000), `tenant-admin` (3001), `storefront` (3002) and `platform-web` (3003);
- `@workspace/ui` with about 60 Base UI shadcn components, `globals.css` tokens and `use-mobile`;
- `@workspace/eslint-config` and `@workspace/typescript-config`;
- `next-themes` and nuqs in each app.

There are no features, no data fetching and no auth yet.

The existing decisions are kept: the `@workspace/*` package scope, the template layout and the shadcn configuration.

Corrections:

1. Move each app's `components/`, `hooks/` and `lib/` folders under `src/`. The `@/*` alias already points at `src/`, so today's `@/../components/theme-provider` import works around the mismatch.
2. Replace the `cn` dependency (§4).
3. Move `@tanstack/react-table` and `zod` out of `ui` into the packages that use them.
4. Add the fifth app `affiliate-portal` on port 3004.
5. Local development uses the host-based edge of §38.6. Ports are only the upstream addresses behind it.

### 5.2 Workspace Layout

| Workspace | Kind | Responsibility (one sentence) | Used by |
|---|---|---|---|
| `apps/platform-web` | App | The public platform website, tenant signup and the social-login relay | Visitors, registrants |
| `apps/platform-admin` | App | Landlord administration for platform users | Platform users |
| `apps/affiliate-portal` | App | The affiliate programme portal | Affiliates |
| `apps/tenant-admin` | App | Tenant back office, POS, kitchen display and seller portal | Staff, sellers |
| `apps/storefront` | App | Tenant storefront, customer account and guest checkout | Customers, guests |
| `packages/contract` | Generated and overlay | OpenAPI path types, generated registries (modules, limits, permissions, routes) and the hand-maintained response overlay | `api-client`, `access`, apps |
| `packages/api-client` | Library | Typed `fetch` client factories, envelope unwrapping, error normalisation, pagination, idempotency and upload helpers | Every app |
| `packages/bff` | Server library | Tenant context resolution, sealed session cookies, the upstream proxy, the path allow-list and CSRF checks | Every app (server only) |
| `packages/access` | Library | Interprets the access snapshot (permissions, module states, limits) and exposes gates | `tenant-admin`, `platform-admin` |
| `packages/ui` | Library | The design system: tokens, primitives, composed components and the icon facade | Every app |
| `packages/admin-kit` | Library | Admin shell, navigation registry engine, data table, CRUD templates, API-error form binding, bulk bar, import and export UIs | `tenant-admin`, `platform-admin`, `affiliate-portal` |
| `packages/custom-fields` | Library | Custom-field inputs, display and schema building from definitions | `tenant-admin`, `storefront` |
| `packages/cms-render` | Library | CMS section and rich-text renderers shared by landlord and tenant scopes | `platform-web`, `storefront` |
| `packages/realtime` | Library | The Echo client factory with a BFF authoriser, and channel hooks | `tenant-admin`, `platform-admin`, `storefront` |
| `packages/format` | Library | Money, quantity, number, date and relative-time formatting from backend display settings | Every app |
| `packages/testing` | Dev library | MSW handlers, envelope factories and Playwright fixtures | Every app (dev only) |
| `packages/eslint-config` | Config | ESLint flat configs and boundary rules | Every workspace |
| `packages/typescript-config` | Config | Base `tsconfig` presets | Every workspace |

The prompt's candidate packages `auth`, `types`, `validation`, `permissions`, `config` and `utils` are not created:

- **auth** is `packages/bff` (server) plus per-app auth features.
- **types** is `packages/contract`.
- **validation:** Zod schemas belong to the feature that owns the form.
- **permissions** is `packages/access`.
- **config** is split between the two config packages and each app's `env.ts`.
- **utils** is a dumping ground by definition.

### 5.3 Package Admission Rule

A new package is created only when all three conditions hold:

1. At least two apps use it, or it isolates a hard boundary. `contract` isolates generated code, and `bff` isolates server-only code.
2. Its responsibility can be stated in one sentence.
3. Its dependencies fit §5.4.

Packages named `utils`, `common`, `shared`, `helpers`, `types` or `lib` are forbidden. Code that fits no package stays in its app.

### 5.4 Dependency Direction

```
apps/*  ──►  admin-kit ──► access ──► contract
   │            │  └─────► api-client ──► contract
   │            └────────► ui
   ├──►  custom-fields ──► ui, contract, format
   ├──►  cms-render ──► ui
   ├──►  realtime ──► api-client
   ├──►  bff ──► contract          (server only)
   └──►  format
testing ──► contract, api-client   (dev only)
```

- Apps depend on packages. A package never imports an app, and no app imports another app.
- `ui` depends on no other workspace package.
- `contract` depends on nothing.
- `bff` is importable only from server code. Its entry points start with `import 'server-only'`.
- Cycles are impossible by construction and are also rejected by lint.

### 5.5 Sharing and Versioning Rules

- Internal packages are never published. They use `"version": "0.0.0"` and `workspace:*`, export TypeScript source (just-in-time packages), and are compiled by each app through `transpilePackages`, as `@workspace/ui` is today.
- Feature code is never shared between apps. Two apps with an "orders" screen do not share it: landlord and tenant resources differ, and so do permissions.
- A breaking package change updates every consumer in the same pull request.

### 5.6 Boundary Enforcement

| Rule | Mechanism |
|---|---|
| Package dependency direction (§5.4) | Turborepo `boundaries` with package tags (`app`, `lib`, `server`, `generated`), run in CI |
| No deep imports into a package | Each package's `exports` map lists its public entry points only |
| No deep imports into a feature (`features/x/components/...` from outside `x`) | ESLint `no-restricted-imports` patterns in `@workspace/eslint-config/next-js` |
| Server-only code stays on the server | The `server-only` import in `bff` and in each app's `src/server/*` |
| No `process.env` outside `src/env.ts` | ESLint `no-process-env` with an override for `env.ts` |
| No raw palette colours in apps | A custom ESLint rule rejecting raw palette classes (`bg-`, `text-` or `border-` followed by a palette name and shade, such as `bg-blue-600`) outside `packages/ui` |
| Icons only from the facade | `no-restricted-imports` for `@hugeicons/*` outside `packages/ui` |

---

# Part II: Platform Plumbing

## 6. Applications and Hosts

### 6.1 Applications

| App | Purpose | Rendering | Session | Upstream |
|---|---|---|---|---|
| `platform-web` | Marketing pages (landlord CMS), pricing, legal documents, blog, FAQ, contact, affiliate referral capture, tenant signup, OAuth relay | Server Components, cached. Signup steps are client islands. | None | Landlord |
| `platform-admin` | Tenants, registrations, domains, plans and prices, feature and limit overrides, subscriptions, transactions, coupons, commissions, gateways, affiliates, CMS, legal documents, module notices, notification templates, settings, platform users and roles, helpdesk, exports, dashboard | Server-rendered shell, TanStack Query data | Platform user | Landlord |
| `affiliate-portal` | Apply, sign in, dashboard, referrals, commissions, payouts, payout details, profile, legal acceptance, notifications | Server-rendered shell, TanStack Query data | Affiliate | Landlord |
| `tenant-admin` | Staff back office for every core and optional module; `/pos` terminal; `/kitchen` display; `/seller` portal | Server-rendered shell, TanStack Query data | Staff; seller (separate cookie) | Tenant |
| `storefront` | Catalogue, product, CMS, blog, cart, checkout, payment return, account, guest orders, support chat, careers, booking, gift cards | Server Components with per-tenant caching; client islands | Customer (optional); guest | Tenant |

### 6.2 Hosts

| Host | Served by | Laravel paths on the host (edge-routed) |
|---|---|---|
| `ROOT` | `platform-web` | `/api/*`, `/sitemap.xml` (landlord); `/app/*` (Reverb WebSocket) |
| `www.ROOT` | Edge 308 to `ROOT` | none |
| `platform.ROOT` | `platform-admin` | none |
| `affiliates.ROOT` | `affiliate-portal` | none |
| `{slug}.admin.ROOT` | `tenant-admin` | none |
| `{slug}.ROOT` and every verified custom domain | `storefront` | `/api/*`, `/sitemap.xml` (tenant) |
| `cdn.ROOT` | CDN in front of public media storage | none |

Why these hosts:

- **Separate origins per trust zone.** The storefront loads tenant-chosen third-party scripts (Google Tag Manager, analytics, Meta pixel: `config/storefront.php`). A script on the same origin can read pages and call same-origin endpoints with the user's cookies, whatever their `Path`. The tenant admin, seller portal, platform admin and affiliate portal therefore each have their own origin (ADR-07).
- **`{slug}.admin.ROOT`, not `admin.ROOT/{slug}`.** Each tenant's admin cookies are host-only and isolated from every other tenant's. The admin host maps to the tenant's permanent subdomain `{slug}.ROOT` without a lookup.
- **No new reserved slugs.** `platform`, `affiliates`, `admin` and `cdn` are already reserved by the backend (§3.1), so no tenant can claim these hosts. Realtime runs on `ROOT/app/*`, the Pusher-protocol path Reverb serves, so no `ws` subdomain is needed.
- **Laravel keeps its public `/api`** on `ROOT` and on tenant hosts. It serves webhooks, native and third-party clients, `/sitemap.xml`, signed download links and the edge TLS check. The web apps do not call these paths from the browser (§8).

### 6.3 What Each App Must Not Do

| App | Must not |
|---|---|
| `platform-web` | Hold a session. Call `/api/admin/*` or `/api/affiliate/*` (except the public affiliate routes of §24.4). |
| `platform-admin` | Call tenant hosts. It reaches tenant data only through landlord endpoints. |
| `affiliate-portal` | Call anything outside `/api/affiliate/*`, `/api/affiliate-program`, `/api/legal-documents/*` and `/api/lookups/*`. |
| `tenant-admin` | Call landlord hosts. Load third-party tracking scripts. Render tenant HTML without sanitisation. |
| `storefront` | Call `/api/admin/*`, `/api/seller/*`, `/api/driver/*`, `/api/internal/*` or `/api/webhooks/*`. |

The BFF allow-list (§8.5) enforces these rules. The table documents them.

---

## 7. Edge Routing and Tenant Resolution

### 7.1 The Edge

One edge proxy terminates TLS for every host, including on-demand certificates for custom domains. The backend already exposes the `ask` endpoint `GET /api/internal/domains/allowed`, protected by `EDGE_ALLOWED_IPS` and `EDGE_SHARED_SECRET`. Caddy is the reference implementation. Any proxy with on-demand TLS, host routing and header control is acceptable.

### 7.2 Routing Table

Rules are evaluated top to bottom, and the first match wins.

| # | Host | Path | Upstream |
|---|---|---|---|
| 1 | `www.ROOT` | any | 308 to `https://ROOT{path}` |
| 2 | `ROOT` | `/app/*` (WebSocket upgrade) | Reverb |
| 3 | `ROOT` | `/api/*`, `/sitemap.xml` | Laravel |
| 4 | `ROOT` | any | `platform-web` |
| 5 | `platform.ROOT` | any | `platform-admin` |
| 6 | `affiliates.ROOT` | any | `affiliate-portal` |
| 7 | `*.admin.ROOT` (exactly one label before `.admin`) | any | `tenant-admin` |
| 8 | `cdn.ROOT` | any | Media CDN origin |
| 9 | `*.ROOT` (exactly one label) | `/api/*`, `/sitemap.xml` | Laravel |
| 10 | `*.ROOT` (exactly one label) | any | `storefront` |
| 11 | any other host (TLS issued only after the `ask` check) | `/api/*`, `/sitemap.xml` | Laravel |
| 12 | any other host | any | `storefront` |

### 7.3 Headers Set by the Edge

The edge MUST:

1. **Remove** any inbound `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Real-IP` or `X-Request-Id` that did not originate at the edge, and every header starting `X-Tenant`.
2. Set `X-Real-IP` and `X-Forwarded-For` to the client address.
3. Set `X-Forwarded-Proto: https`.
4. Set `X-Request-Id` to a new UUID v4.
5. Preserve the original `Host`.

### 7.4 Tenant Resolution

Tenant resolution happens once per request in `packages/bff` `resolveTenantContext(request, appConfig)`, and nowhere else.

| App | Input | API host sent to Laravel | Validation |
|---|---|---|---|
| `storefront` | Request `Host`, port stripped, lower-cased | The same host | A syntactically valid hostname (RFC 1123, ≤ 253 characters). Laravel decides whether it is a tenant: an unknown host returns 404 `not_found`, and the storefront renders "Store not found" with HTTP 404 and `noindex`. |
| `tenant-admin` | Request `Host` | `{slug}.ROOT`, where `{slug}` is the single label before `.admin.ROOT` | `{slug}` matches `^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`. Anything else returns 404 without calling Laravel. |
| `platform-web`, `platform-admin`, `affiliate-portal` | none | `ROOT` | Fixed by configuration |

```ts
type TenantContext =
  | { kind: 'landlord'; apiHost: string }
  | { kind: 'tenant'; apiHost: string; requestHost: string; slug: string | null };
```

`slug` is known on admin hosts and on `{slug}.ROOT`. On custom domains it comes from the storefront config `tenant.slug`.

**Flow:**

```
Browser ──TLS──► Edge (Host preserved, forwarded headers reset)
   ──► Next.js app: proxy.ts validates the host shape (404 on failure)
   ──► route handler or Server Component: resolveTenantContext() → {apiHost}
   ──► upstream(): GET http://laravel.internal/api/... with Host: {apiHost}
   ──► Laravel InitializeTenancyByDomain(Host) → tenant database, or 404
```

### 7.5 Anti-Spoofing Requirements

1. **Host only.** Tenant identity derives from the request `Host`, which TLS binds to a certificate the platform issued. No header, query parameter, cookie, path segment or body field selects a tenant.
2. **Laravel re-resolves.** The BFF sends the tenant host as `Host`, and Laravel resolves the tenant through its own domain lookup. The frontend therefore cannot select a tenant that the host does not identify.
3. **Session binding.** Every session cookie records the `apiHost` it was issued for. The BFF rejects and clears a cookie whose recorded host differs from the current `apiHost`. A token copied to another tenant is useless in any case, because each tenant database has its own token table.
4. **Host-only cookies.** Session cookies use the `__Host-` prefix, so they have no `Domain` attribute and never reach another subdomain.
5. **No tenant in client state.** Client components receive tenant display data for rendering. They never send a tenant identifier to the BFF, and the BFF ignores one if sent.
6. **Tenant-keyed caches.** Every server cache entry includes `apiHost` in its key (§14.4). A cache function without a host argument is a defect, caught by the lint rule of §14.4.
7. **Custom domains** reach the storefront only after TLS issuance, which the backend allows only for verified domains of tenants that may be served.

---

## 8. Backend-for-Frontend Layer

### 8.1 Decision

Every app has a thin BFF built from Next.js route handlers and `packages/bff` (ADR-05).

- Browser code calls only same-origin `/bff/*` routes.
- Server Components call Laravel directly from the Next.js server through the same `upstream()` function.
- The BFF attaches the bearer token from an encrypted HttpOnly cookie, sets the tenant `Host`, forwards client identity headers, and returns Laravel's response with a filtered header set (§8.3).
- The BFF contains no business logic. It never aggregates, reshapes or caches authenticated responses. It has two narrow exceptions, both at the auth boundary: it seals tokens from auth responses (§8.2) and removes guest tokens from cart responses (§8.4).

### 8.2 Session Cookies

| App | Cookie | Actor | Max-Age |
|---|---|---|---|
| `platform-admin` | `__Host-plat` | Platform user | Until `expires_at`; refreshed (§9.3) |
| `affiliate-portal` | `__Host-aff` | Affiliate | Until `expires_at` |
| `tenant-admin` | `__Host-staff` | Staff | Until `expires_at`; refreshed (§9.3) |
| `tenant-admin` | `__Host-seller` | Seller | Until `expires_at` |
| `storefront` | `__Host-cust` | Customer | Until `expires_at` |
| `storefront` | `__Host-guest` | Guest cart token | 30 days, renewed on each cart write |

- **Attributes:** `HttpOnly; Secure; SameSite=Lax; Path=/`. `Lax`, not `Strict`, because payment gateways and the OAuth relay return the customer with a top-level cross-site navigation that must carry the session.
- **Contents:** a JWE (`dir`, `A256GCM`) sealed with `SESSION_SECRET`. `SESSION_SECRET_PREVIOUS` is accepted for decryption during key rotation.

```ts
type SealedSession = {
  v: 1;
  actor: 'platform' | 'affiliate' | 'staff' | 'seller' | 'customer';
  token: string;      // Sanctum plain-text token "id|tea_…"
  apiHost: string;    // host the token was issued for (§7.5)
  issuedAt: number;   // epoch seconds
  expiresAt: number;  // from the login response's expires_at; fallback issuedAt + SANCTUM_TTL_MINUTES
};
```

Cookies cannot be changed while a Server Component renders. A Server Component that finds an expired or rejected session redirects to `/bff/session/expired?next={path}`. That route handler clears the cookie and redirects to the login page.

### 8.3 Upstream Requests

```ts
upstream(ctx: RequestContext, init: {
  method: string;
  path: string;              // "/api/admin/products"
  search?: string;
  body?: BodyInit | null;    // streamed; multipart passes through unchanged
  headers?: HeadersInit;     // Content-Type and Idempotency-Key only
  timeoutMs?: number;        // default 30 000; uploads and imports 120 000
  session?: 'required' | 'optional' | 'none';
}): Promise<Response>
```

The request is built as follows.

| Item | Value |
|---|---|
| URL | `${LARAVEL_INTERNAL_URL}${path}${search}`: a private network address, never the public edge |
| `Host` | `ctx.tenant.apiHost`. This is how Laravel resolves the tenant or landlord (§3.1). The upstream uses `undici`'s `request` with an explicit `Host` header, because it must set `Host`; this is covered by an integration test (§35.3). |
| `X-Forwarded-For`, `X-Real-IP` | The client IP from the edge's `X-Real-IP`. Laravel trusts it from `TRUSTED_PROXIES` (D-141). |
| `X-Forwarded-Proto` | `https` |
| `User-Agent` | The browser's user agent. Legal acceptance and token device names record it. |
| `Accept` | `application/json` |
| `Accept-Language` | The browser's value |
| `Authorization` | `Bearer {token}` when the route's actor session exists |
| `X-Guest-Token` | From `__Host-guest`, on storefront routes only |
| `X-Request-Id` | The edge's value, or a new UUID in local development |
| `Idempotency-Key`, `Content-Type` | Passed through |

**Response handling:**

- Status and body pass through, except for the auth and cart rules of §8.4.
- Response headers are filtered to an allow-list: `Content-Type`, `Content-Disposition`, `Content-Length`, `Retry-After`, `X-Request-Id`, `X-Module-Notice`, `Idempotent-Replayed` and `Location`.
- Downloads use `redirect: 'manual'`, so a 302 to a temporary storage URL reaches the browser.
- A 401 on a request that carried a session clears that session cookie in the same response.
- A timeout or network error becomes 502 with the envelope `{success:false, message:"The service is temporarily unavailable.", data:null, meta:{error_code:"upstream_unavailable", details:{}}, errors:{}}`. This is a frontend-only code.

### 8.4 Auth and Cart Response Handling

| Route (upstream) | BFF behaviour |
|---|---|
| Any login, `register`, social `callback`, staff or platform-user `refresh` | On 2xx: seal `data.token` and `data.expires_at` into the actor's cookie, and return the envelope with `data.token` and `data.token_type` removed. |
| Any `logout` | Call upstream (best effort, 3-second timeout), then always clear the cookie. |
| Cart routes and `POST /api/orders` | If `data.guest_token` is present: seal it into `__Host-guest` and replace the value with `null` in the body. Browser code never sees the guest token. |
| Customer `login`, `register`, social `callback` | Send `X-Guest-Token` so the backend merges the guest cart. On success, clear `__Host-guest`. |

### 8.5 BFF Routes and Allow-Lists

| App | Route | Forwards to | Session |
|---|---|---|---|
| `platform-admin` | `/bff/api/[...path]` | Landlord `/api/admin/*` (except `/api/admin/auth/login`, `/password/*`, `/email/*`), `/api/lookups/*`, `/api/broadcasting/auth` | `__Host-plat` |
| `affiliate-portal` | `/bff/api/[...path]` | Landlord `/api/affiliate/*` (except auth), `/api/affiliate-program`, `/api/legal-documents/*`, `/api/lookups/*` | `__Host-aff` |
| `platform-web` | `/bff/api/[...path]` | Landlord `/api/register*`, `/api/plans*`, `/api/platform-coupons/validate`, `/api/affiliate-clicks`, `/api/contact`, `/api/legal-documents/*`, `/api/lookups/*`, `/api/cms/*`, `/api/platform/config` | none |
| `tenant-admin` | `/bff/staff/api/[...path]` | Tenant `/api/admin/*` (except auth login and password routes), `/api/module-notices`, `/api/lookups/*`, `/api/broadcasting/auth` | `__Host-staff` |
| `tenant-admin` | `/bff/seller/api/[...path]` | Tenant `/api/seller/*` (except auth), `/api/lookups/*` | `__Host-seller` |
| `storefront` | `/bff/api/[...path]` | Tenant `/api/*` except `/api/admin/*`, `/api/seller/*`, `/api/driver/*`, `/api/internal/*`, `/api/webhooks/*` and auth routes | `__Host-cust` (optional), `__Host-guest` |
| every app with sessions | `/bff/auth/{action}` | The actor's auth routes (§9) | Sets or clears the cookie |
| every app with sessions | `/bff/session` | none (reads the cookie) | Returns `{authenticated, actor, expiresAt}`, never the token |
| every app with sessions | `/bff/session/expired` | none | Clears the cookie and redirects to login |
| `storefront`, `tenant-admin`, `platform-admin` | `/bff/broadcasting/auth` | The app's broadcasting auth route (§21.2) | App session, or the guest cookie for storefront guest support |

The allow-list is a list of method and path-prefix pairs in each app's `src/config/bff.config.ts`. The path is normalised before matching: `..` segments, encoded slashes and double slashes are rejected. A path outside the list returns 404 without calling Laravel.

### 8.6 CSRF Protection

The BFF is cookie-authenticated, so:

1. `SameSite=Lax` stops cookies on cross-site subresource and `POST` requests.
2. Every non-`GET`, non-`HEAD` BFF request MUST carry an `Origin` equal to the app's own origin. Otherwise the BFF answers 403 with `error_code: csrf_rejected` (a frontend-only code).
3. Every non-`GET` request MUST carry `X-Requested-With: bff`. A cross-origin page cannot add it without a preflight, and the BFF answers no preflight.
4. `GET` routes never change state. The allow-list rejects `GET` for non-read upstream routes.

### 8.7 Why a BFF

| Concern | Browser calls Laravel with a token in JavaScript | BFF |
|---|---|---|
| Token theft by XSS or tenant scripts | Token readable | Token never in JavaScript |
| Server rendering of authenticated pages | Needs a server-readable credential anyway | Natural |
| Tenant identity for `{slug}.admin.ROOT` | Browser origin differs from the API host, so a second identification mechanism is needed | The server sets `Host` |
| CORS | One origin set per app and per custom domain | Not involved |
| Latency | One hop | One extra private-network hop, low single-digit milliseconds |

The extra hop is accepted.

---

## 9. Authentication

### 9.1 Per-Actor Flows

| Actor | App and login URL | Upstream login | Session source of truth | Expiry |
|---|---|---|---|---|
| Platform user | `platform.ROOT/login` | Landlord `POST /api/admin/auth/login` | `GET /api/admin/auth/me` | Refreshed like staff (§9.3) through `POST /api/admin/auth/refresh` on the landlord (BG-10, D-143). |
| Affiliate | `affiliates.ROOT/login` | `POST /api/affiliate/auth/login` | `GET /api/affiliate/profile` | Re-login |
| Staff | `{slug}.admin.ROOT/login` | Tenant `POST /api/admin/auth/login` | `GET /api/admin/auth/me` | Proactive refresh (§9.3) |
| Seller | `{slug}.admin.ROOT/seller/login` | `POST /api/seller/auth/login` | `GET /api/seller/profile` | Re-login |
| Customer | Storefront `/account/login` | `POST /api/auth/login` or social login (§9.5), with the guest token | `GET /api/account` | Re-login; cart and return URL preserved |
| Guest | Storefront | The first cart write issues the guest token | `__Host-guest` | 30-day renewal |

### 9.2 Login Handling

1. The login form posts `{email, password}` to `/bff/auth/login` with the CSRF headers (§8.6).
2. The route handler validates the body with Zod, resolves the tenant context and calls the upstream login.
3. On 200 the BFF seals the session (§8.4) and returns `{user}`.
4. On 401, 422 or 429 the upstream envelope is returned. The form shows `message`, maps field errors and, for 429, shows a `Retry-After` countdown.
5. On 402, 403 `tenant_suspended`, 410 or 503, the login page renders the matching tenant state (§11.4).
6. After login the page navigates to `next` only when `next` is a relative path that starts with a single `/` and not `//` or `/\`. Otherwise it goes to the app's home.
7. Staff login returns the user and permissions, but the shell always loads `me` for the full snapshot (§10.2).

### 9.3 Staff and Platform-User Token Refresh

`POST /api/admin/auth/refresh` revokes the current token and issues a new one: on the tenant host for staff, and on the landlord host for platform users (D-143). Both tokens last 12 hours.

1. The client shell schedules a refresh when 75% of the lifetime has passed, using `expiresAt` from `/bff/session`.
2. Only one tab refreshes: the call runs inside `navigator.locks.request('{app}-session-refresh', …)`. Other tabs share the cookie and see the new expiry on their next `/bff/session` read.
3. `/bff/auth/refresh` calls the upstream refresh with the current token and seals the new one.
4. A request in flight with the old token may get 401 after the swap. The `sessionRetry` middleware (§12.3) calls `/bff/session` once and retries once if the session is valid. Otherwise it redirects to login with `next`.
5. After 30 minutes without user interaction the shell stops refreshing, so an abandoned session expires naturally.

Customers, affiliates, sellers and drivers have no refresh endpoint. Their sessions last the Sanctum token lifetime, 30 days by default (BG-10, D-143).

### 9.4 Registration and Account Flows

| Flow | App | Frontend route | API |
|---|---|---|---|
| Tenant signup | `platform-web` | `/signup`, `/register/verify`, `/signup/payment`, `/signup/status` (§24.3) | `/api/register*` |
| Customer registration | `storefront` | `/account/register` | `POST /api/auth/register`, with the guest token and the store's public `customer` custom fields |
| Customer email verification | `storefront` | `/verify-email` (the backend link) | `POST /api/auth/email/verify` with the link's query parameters; `POST /api/auth/email/resend` |
| Customer password reset | `storefront` | `/forgot-password`, `/reset-password` (the backend link) | `POST /api/auth/password/forgot`, `/reset` |
| Customer set password (social-only accounts) | `storefront` | `/account/security` | `POST /api/auth/password/set` |
| Seller registration | `tenant-admin` | `/seller/register` | `POST /api/seller/auth/register`. The seller starts `pending` and cannot log in until approved. |
| Seller password reset | `tenant-admin` | `/seller/forgot-password`, `/seller/reset-password` | `/api/seller/auth/password/*` |
| Staff password reset | `tenant-admin` | `/forgot-password`, `/reset-password` | `/api/admin/auth/password/*` |
| Platform user first password and reset | `platform-admin` | `/reset-password` (the backend uses the reset link for invitations) | `/api/admin/auth/password/*` |
| Platform user verification | `platform-admin` | `/verify-email` | `POST /api/admin/auth/email/verify` |
| Affiliate apply, verify, reset | `affiliate-portal` | `/apply`, `/verify-email`, `/forgot-password`, `/reset-password` | `/api/affiliate/auth/*` |
| Password change | every app with sessions | Security settings | The actor's `PATCH …/password`. There is none for platform users (BG-10). |

Verification and reset pages send the link's query string unchanged as the request body, whether it holds signed parameters (`id`, `hash`, `expires`, `signature`) or reset parameters (`token`, `email`). They do not interpret it.

### 9.5 Customer Social Login (Google and Facebook)

The backend implements OAuth with a single platform-wide redirect URI per provider (D-132):

- **Redirect URI:** `GOOGLE_REDIRECT_URI` and `FACEBOOK_REDIRECT_URI`.
- **State:** single-use, valid for 10 minutes, and bound to the tenant, provider and intent. The backend prefixes it with the base64url-encoded storefront URL.
- **No provider tokens are stored.**
- **Endpoints:**

| Endpoint | Body | Response `data` |
|---|---|---|
| `GET /api/auth/social/providers` (also `social_login_providers` in the storefront config) | none | `{providers: ['google', 'facebook']}`, only the configured ones |
| `POST /api/auth/social/{provider}/redirect` | `custom_fields?` (the store's registration fields, used only if an account is created) | `{authorization_url, state, expires_at}` |
| `POST /api/auth/social/{provider}/callback` (guest token accepted) | `code`, `state`, `device_name?` | `{token, token_type, expires_at, registered, customer}`. 201 when an account was created, 200 otherwise. |
| `GET /api/account/social-accounts` | none | `{has_password, available, linked: [{provider, email, linked_at, last_used_at}]}` |
| `POST /api/account/social-accounts/{provider}/redirect` | none | `{authorization_url, state}` for linking |
| `POST /api/account/social-accounts/{provider}/callback` | `code`, `state` | `{provider, email}` |
| `DELETE /api/account/social-accounts/{provider}` | none | `null` |

**Sign-in flow (full-page redirect):**

```
Storefront /account/login ── click "Continue with Google"
  1. POST /bff/auth/social/google/start {custom_fields?, next}
       BFF → POST /api/auth/social/google/redirect → {authorization_url, state}
       BFF stores {state → intent:'login', next} in a sealed, 10-minute __Host-oauth cookie
       → returns {authorization_url}
  2. window.location.assign(authorization_url)                 (provider consent)
  3. Provider → https://ROOT/oauth/google/callback?code&state   (platform-web relay, §24.5)
  4. Relay decodes the state prefix → storefront base URL, validates it,
       → 303 to {storefront}/auth/social/google/callback?code&state
  5. Storefront callback page (server route handler):
       reads __Host-oauth, requires the same state, and checks the intent
       POST /api/auth/social/google/callback {code, state} with X-Guest-Token
       → seals __Host-cust, clears __Host-guest and __Host-oauth
       → 303 to next (validated as in §9.2), or /account
```

- **Redirect, not popup.** Popups are blocked on many mobile browsers, break in in-app browsers (Instagram, Facebook) and complicate focus management. A full redirect works everywhere, and the cart is preserved server-side by the guest token.
- **Linking** uses the same relay. The storefront start route calls the `/api/account/social-accounts/{provider}/redirect` endpoint and records `intent:'link'`. The callback page then calls the link callback and returns to `/account/security`.
- **Errors** are shown on `/account/login` (or `/account/security` for linking), with the backend `message` and these specific treatments:

| Code | Presentation |
|---|---|
| `social_state_invalid` | "This sign-in link expired. Try again." |
| `social_auth_failed` | "{Provider} could not confirm your sign-in." with **Retry** |
| `social_provider_unavailable` | Hide the provider button and refetch the storefront config. |
| `social_email_required` | "Your {Provider} account has no email address we can use. Register with email instead." |
| `social_email_unverified` | "An account with this email exists. Sign in with your password, then link {Provider} from your account." Link to sign-in, with the email prefilled. |
| `account_email_unverified` | "Verify this account's email or reset its password first." Offer resend and reset. |
| `social_identity_in_use`, `social_provider_already_linked` | Explain and link to `/account/security`. |
| `account_disabled` | "This account is disabled." Link to the store contact page. |
| `last_login_method` (unlink) | "Set a password before unlinking." Link to the set-password form. |

- **Account page.** `/account/security` shows the linked providers, **Link** and **Unlink** buttons, and, when `has_password` is false, a **Set password** form. Unlink is disabled when it would remove the last sign-in method.
- **Loading states.** The start button shows a spinner until the navigation begins. The callback page renders a full-page "Signing you in…" state, and the handler completes within one server round-trip.
- **A registered customer** (201) sees a welcome toast. When the store has required registration custom fields that the provider could not supply, the backend enforces them. On 422 the storefront shows the registration form prefilled, and restarting the flow sends `custom_fields`.

### 9.6 Logout

`/bff/auth/logout`:

1. calls the upstream logout (best effort, 3-second timeout);
2. clears the cookie;
3. clears the browser query cache (`queryClient.clear()`);
4. navigates to the login page.

A `BroadcastChannel('session:{actor}')` message logs out the actor's other tabs.

---

## 10. Authorisation and the Access Snapshot

### 10.1 Rules

1. The frontend hides or disables what a user cannot use. It never treats hiding as protection.
2. Every mutation handles 403 `forbidden`, even when the control was shown, because permissions can change between snapshot and request.
3. Record-scope failures are 404. The UI shows "Not found", never "Forbidden".
4. Permission names are never typed by hand. They come from the generated permission union (§13), and gates reference Laravel route names.

### 10.2 The Access Snapshot

Both admin apps load one snapshot per session into TanStack Query under `['session', 'me']`:

```ts
type AccessSnapshot = {
  user: UserSummary;
  roles: string[];
  permissions: ReadonlySet<Permission>;               // generated union per context
  modules: Readonly<Record<ModuleKey, ModuleState>>;  // tenant only
  isOwner: boolean;                                   // tenant only
  display: DisplaySettings;                           // timezone, date and time format
  paymentMode: 'test' | 'live';                       // tenant only
  tenantStatus: TenantStatus | null;                  // owner only
  subscriptionStatus: SubscriptionStatus | null;      // owner only
  pendingLegalDocuments: LegalDocumentSummary[];      // owner only
};
```

- It is loaded in the server layout, so the first render is correct, and hydrated into the client cache.
- It is refetched on window focus, every 5 minutes, after any role or module mutation, and after any 403 with a module code or `forbidden`.
- Sellers and affiliates have no permissions. Every portal route is available to an approved account.

### 10.3 Gates

`packages/access` exports pure functions and thin React bindings.

```ts
canRoute(snapshot, manifest, 'tenant.catalog.admin.products.store'): boolean
canRead(snapshot, moduleKey): boolean   // enabled, or disabled/locked and read_when_inactive
canWrite(snapshot, moduleKey): boolean  // enabled
moduleState(snapshot, moduleKey): ModuleState

<Can route="tenant.returns.admin.approve">…</Can>
<Can route="…" fallback={<DisabledAction reason={…} />}>…</Can>
useCan(routeName): boolean
```

**Where gates run.** The route manifest is about 275 KB, so it never ships to the browser. The admin layout (a Server Component) evaluates `canRoute` for every route of the app's groups (`allowedRoutes(snapshot, manifest, ['tenant.admin'])`) and passes the allowed route names to `AccessProvider`; `useCan` and `<Can>` check membership. Navigation is filtered on the server the same way. Refreshing the snapshot (focus, access errors) re-renders the layout with `router.refresh()`.

`canRoute` applies the backend order for the route's manifest entry (§13.4):

1. **Module state.** The state must allow the method: `enabled`; or `disabled` or `locked` with `read_when_inactive` and a `GET`; or a wind-down route. `suspended` never passes.
2. **Permission.** The route's permission is held, or the route has none.

Tenant status, subscription restriction and limits are not part of `canRoute`. They are page-level and action-level states (§11).

### 10.4 Disabled Versus Hidden

| Situation | Treatment |
|---|---|
| The user lacks the permission for an action on a visible page | Hide the action. |
| The module is `disabled` or `locked` and the action is a write | Show it disabled. A tooltip explains the state and links to the module card (owner) or says "Ask your store owner". |
| A usage limit is reached | Show the action disabled, with the usage and an upgrade path (§11.3). |
| The subscription is read-only | Show writes disabled, with a tooltip linking to billing. |
| A page the user cannot read | Not in navigation. A direct URL renders the access state (§30). |

---

## 11. Modules, Limits, Tenant Status and Subscription UX

### 11.1 One Module System

| Concept | Frontend source | Frontend responsibility |
|---|---|---|
| Code module (`app/Modules/{Module}`) | None at runtime; mirrored by the feature folder (§16) | Code organisation |
| Feature key | `ModuleKey` union generated from `config/modules.php` (§13.3) | Tag navigation entries and pages |
| Entitlement, activation, state, dependencies | `GET /api/admin/modules` (`state`, `entitled`, `source`, `activation`, `requires`, `dependents`, `missing_requirements`, `inactive_periods`) and `modules` in `me` | Catalogue, navigation, page and action states |
| Usage limit | `GET /api/admin/billing/usage`; 403 `limit_reached` | Meters and the limit dialog |
| Permission | `permissions` in `me` | Action visibility |
| Module notice | `GET /api/module-notices`; `X-Module-Notice`; 503 `maintenance` | Banners and maintenance states |

The frontend MUST NOT:

- hand-write module keys, requirements, activation modes or read-when-inactive flags;
- derive a state from plan data;
- decide that something is "core";
- store module state outside the `['session', 'me']` and `['modules']` queries.

### 11.2 State UX Matrix

| State | Navigation | Direct URL to a module page | Writes | Catalogue card |
|---|---|---|---|---|
| `enabled` | Shown | Normal | Per permission | "Enabled", with Disable if permitted |
| `disabled`, read-when-inactive | "Inactive modules" group, muted, badge "Disabled" | Read-only view with banner "This module is disabled. Existing records are read-only." Owner sees **Enable**. | Hidden, except wind-down routes | "Disabled", with Enable |
| `disabled`, not read-when-inactive | Hidden | "This module is disabled" page, with Enable for the owner | n/a | "Disabled", with Enable |
| `locked`, read-when-inactive | "Inactive modules" group, badge "Not in plan" | Read-only view with a banner listing `details.entitled_by_plans` and a billing link for billing users | Hidden, except wind-down routes | "Not in your plan", with the plans that include it |
| `locked`, not read-when-inactive | Hidden | Upgrade page | n/a | As above |
| `suspended` | Hidden | "Suspended by the platform. Contact support." Links to Platform support. | None | "Suspended" |
| `available` | Hidden | "Available on your plan but not enabled", with Enable for the owner | n/a | "Available", with Enable |
| `unavailable` | Hidden | Upgrade page listing the plans that include it | n/a | "Upgrade to unlock" |
| `details.reason = requirement_not_enabled` | As its state | The banner names the missing module and links to it | As its state | Shows the missing requirement |

**Catalogue.** `tenant-admin` `/settings/modules` uses `GET /api/admin/modules`.

- **Enable** (`POST /api/admin/modules/{key}/enable`) shows a confirmation that lists requirements. On 422 `module_requirements_not_enabled` it lists the modules to enable first.
- **Disable** shows a confirmation stating that data is kept read-only. On 422 `module_has_dependents` it lists the dependents. On 422 `module_disable_blocked` it lists `details` verbatim.
- Afterwards the app invalidates `['session', 'me']` and `['modules']`, and navigation re-derives from them.

`platform-admin` shows the same data per tenant (`GET /api/admin/tenants/{tenant}/modules`) with feature and limit override management.

### 11.3 Plan Limits

**Proactive.** A list page whose create route carries a usage limit (from the route manifest, §13.4) shows a meter at ≥ 80% usage, for example "42 of 50 products". At 100% **Create** is disabled, with the meter and an upgrade path. Usage comes from `GET /api/admin/billing/usage`. Users without that route's permission get no meter and rely on the reactive path.

**Reactive.** A 403 `limit_reached` opens the limit dialog. It reads "Your plan allows {limit_value} {label}. You are using {used}.", with the label from the limit registry. Owners and billing users get **Compare plans**, and others get "Ask your store owner to upgrade." Unsaved input is preserved.

**Over the limit after a downgrade** is legitimate. The meter shows "55 of 50" with an explanation, and existing records stay usable.

### 11.4 Tenant Status

| Condition | Signal | `tenant-admin` | `storefront` |
|---|---|---|---|
| Awaiting first payment | 402 `subscription_payment_required` | Full-page billing gate; only `/settings/billing/*` renders | Unavailable page (503, `Retry-After: 3600`, `noindex`) |
| Provisioning or failed | 503 `tenant_provisioning` | "Your store is being set up", polling every 15 seconds | Unavailable page (503) |
| Suspended | 403 `tenant_suspended` | Full-page "Store suspended" with support contact. Logout is the only action. | Unavailable page (503) |
| Closed or purged | 410 `tenant_closed` | Full-page "Store closed" | Closed page (410, `noindex`) |
| Platform maintenance | 503 `maintenance` | Maintenance page, polling every 60 seconds | Maintenance page (503, `Retry-After`) |
| Unknown host | 404 `not_found` | 404 page | "Store not found" (404, `noindex`) |

The admin layout detects these from the `me` call, or from its failure, before rendering the shell.

### 11.5 Subscription Restrictions

| Condition | Signal | UX |
|---|---|---|
| Past due, `restriction = read_only` | `subscription_status` in `me` (owner); 402 `subscription_past_due` on admin writes | Persistent banner: "Your subscription payment is overdue. The admin is read-only until payment is made." Owners and billing users see **Pay now**. Writes show disabled. The storefront keeps selling. |
| Past due, `restriction = blocked` | 402 on admin writes and on customer-facing routes | Admin: billing gate. Storefront: unavailable page. |
| Trialing, ending within 7 days | `subscription_status = trialing` and `trial_ends_at` (`GET /api/admin/billing/subscription`) | Owner banner |
| Cancelled before `ends_at` | Subscription resource | Owner banner with the end date and **Resubscribe** |

Non-owner staff do not receive `subscription_status`. For them, the first 402 on a write puts the session into a read-only state (`['session', 'restriction']`). A banner without a pay button reads "Ask your store owner to update billing."

### 11.6 Legal Re-acceptance and Onboarding

- **Legal re-acceptance.** When `pendingLegalDocuments` is non-empty for the owner, a persistent, non-blocking banner opens a dialog that renders each document and posts `POST /api/admin/legal-acceptances`. The backend does not block access, so neither does the frontend. The affiliate portal uses `GET /api/affiliate/legal-documents/pending` and `POST /api/affiliate/legal-acceptances` the same way.
- **Onboarding.** The owner dashboard shows `GET /api/admin/onboarding` until every required step is complete or it is dismissed (§27.3).

### 11.7 Module Notices

- The admin shell polls `GET /api/module-notices` every 5 minutes, and immediately when a response carries an `X-Module-Notice` id that is not in the cache.
- A module's notice shows as a banner on that module's pages. A `core` notice shows everywhere.
- A 503 `maintenance` on a module route renders the maintenance state with the notice title, polling every 60 seconds. A read-only maintenance notice disables writes with the notice text.
- The storefront shows notices for storefront-visible modules as inline, non-blocking text next to the affected feature.

---

## 12. API Client

### 12.1 Decision

`packages/api-client` wraps native `fetch` with `openapi-fetch`, typed by the generated paths (§13), and unwraps the envelope in one place. Axios is not used.

### 12.2 Client Factories

```ts
import createClient from 'openapi-fetch';
import type { paths as TenantPaths } from '@workspace/contract/tenant';

export function createTenantClient(opts: {
  baseUrl: string;           // browser: "/bff/staff/api"; server: bound to upstream()
  fetch?: typeof fetch;      // server: packages/bff upstream-bound fetch
  middleware?: Middleware[];
}) {
  const client = createClient<TenantPaths>({ baseUrl: opts.baseUrl, fetch: opts.fetch, querySerializer });
  client.use(requestId, csrf, envelope, ...(opts.middleware ?? []));
  return client;
}
```

- A matching `createLandlordClient` exists.
- OpenAPI paths are relative to `/api` (§3.9), so the browser base URL is the BFF prefix plus `/api`. For example, `client.GET('/admin/products')` requests `/bff/staff/api/admin/products`.
- On the server the same factory receives a `fetch` bound to `upstream()`, which calls Laravel directly with the session.

Features never read `{data, error, response}` inline. They wrap the typed call in an unwrapping helper, which keeps openapi-fetch's full path, parameter and body typing:

```ts
const products = await unwrapPage(api.GET('/admin/products', { params: { query } }))          // → Page<Product>
const product  = await unwrap(api.GET('/admin/products/{product}', { params: { path } }))     // → Product
const created  = await unwrap(api.POST('/admin/products', { body }))                           // → Product
const inbox    = await unwrapWithMeta(api.GET('/admin/notifications'))                         // → { data, meta, message }
```

`unwrap`, `unwrapPage`, `unwrapCursorPage` and `unwrapWithMeta` check the envelope at runtime, unwrap `data`, split `meta.pagination` from other meta, and throw `ApiError` on failure (a rejected `fetch` becomes `network_error`). The return type is the generated response type; for the few untyped operations the feature normalises the value at the boundary (§13.2).

### 12.3 Middleware

| Middleware | Browser | Server | Behaviour |
|---|---|---|---|
| `requestId` | Yes | Yes | Sets `X-Request-Id` (UUID v4) when absent, and keeps it for error display. |
| `csrf` | Yes | No | Adds `X-Requested-With: bff` to non-`GET` requests. |
| `envelope` | Yes | Yes | Parses the envelope. On `success:false` or a non-2xx status it throws `ApiError`. It detects `X-Module-Notice`. |
| `sessionRetry` | Yes | No | On 401: calls `/bff/session`, retries once if still valid, otherwise emits `session-expired`. |
| `moduleNotice` | Yes | No | Invalidates the notices query when an `X-Module-Notice` id is new. |

### 12.4 Error Normalisation

```ts
class ApiError extends Error {
  readonly status: number;                        // HTTP status, 0 for network failure
  readonly code: ApiErrorCode;                    // meta.error_code, or a frontend-only code
  readonly message: string;                       // envelope message (safe to show)
  readonly details: Record<string, unknown>;      // meta.details
  readonly fieldErrors: Record<string, string[]>; // errors (422)
  readonly requestId: string | null;              // X-Request-Id of the response
  readonly retryAfter: number | null;             // Retry-After seconds
}
```

`ApiErrorCode` is the union of the generated `error-codes.json` (BG-07, resolved in D-142) and these frontend-only codes, widened with `(string & {})`, because a few backend codes are built at runtime and cannot be listed. An unknown code is handled by its HTTP status.

| Frontend-only code | Meaning |
|---|---|
| `network_error` | The browser could not reach the BFF (status 0). |
| `upstream_unavailable` | The BFF could not reach Laravel (502). |
| `csrf_rejected` | The BFF rejected the request's origin (403). |
| `invalid_response` | A body that is not a valid envelope |

Helpers: `isModuleError(e)`, `isLimitError(e)`, `isConflict(e, code?)`, `isValidation(e)`, `isTenantState(e)`.

### 12.5 Request Conventions

- **Queries.** Laravel bracket style: `status[]=paid&status[]=shipped`, `cf[colour]=red`, `cf[weight][from]=1`. Sorting uses `sort=field` and `sort=-field` on admin lists, and named sort values on the storefront catalogue. A custom `querySerializer` implements this once.
- **Bodies.** JSON with `snake_case` keys exactly as the API defines them. Keys are never converted to camelCase.
- **Money and quantities** are sent as decimal strings, never numbers.
- **Dates** are `YYYY-MM-DD`. Date-times are ISO 8601 with offset, and inputs are interpreted in the tenant timezone (§34.3).
- **Multipart.** A `FormData` body with `Content-Type` left to the browser. The BFF streams it unchanged.
- **Cancellation and timeouts.** Every request uses TanStack Query's signal combined with `AbortSignal.timeout(30_000)`, or `120_000` for uploads.
- **Retries.** Queries retry twice with exponential backoff on `network_error`, `upstream_unavailable`, 503 without the `maintenance` code, and 429 after `Retry-After`. Mutations never retry automatically, except the idempotent retries of §12.6.

### 12.6 Idempotency

The routes with `idempotency` middleware are listed in the route manifest (§13.4). They cover order placement and payment initiation, refunds and manual payments, gift-card purchase and issue, installment payments, payouts, subscription billing actions and POS actions. For each:

1. The key is created once per logical action, when the user starts it (for example on entering the payment step), with `crypto.randomUUID()`.
2. It is kept with the pending action, and in `sessionStorage` for checkout and payment, so a reload does not create a second key.
3. Retries of the same action reuse the key: network failures, 5xx and 409 `idempotency_in_progress`. `idempotency_in_progress` retries after `Retry-After` (default 2 seconds), up to 5 times.
4. A changed payload gets a new key. A 409 `idempotency_conflict` means a frontend defect: it is logged with the request ID, and the user sees a generic retry message that uses a new key.
5. `Idempotent-Replayed: true` is treated exactly like the original response.

POS sales carry `idempotency_key` in the body (§27.6).

### 12.7 Pagination

```ts
type Page<T> = { items: T[]; pagination: LengthAwarePagination; links: PageLinks; meta: Record<string, unknown> };
type CursorPage<T> = { items: T[]; pagination: CursorPagination; links: CursorLinks };
```

- Length-aware lists use `useQuery` with `placeholderData: keepPreviousData`.
- Cursor lists (inboxes, conversations, logs, POS sessions) use `useInfiniteQuery` with `getNextPageParam: (p) => p.pagination.next_cursor ?? undefined`.
- `per_page` accepts 15, 25, 50 or 100, the backend maximum.

---

## 13. API Contract and Type Generation

### 13.1 Assessment

| Contract part | Quality today | Source used by the frontend |
|---|---|---|
| Paths, methods, operation IDs (= route names) | Complete: 232 landlord and 996 tenant operations | Generated |
| Path and query parameters, enums, validation limits | Good | Generated |
| Request bodies | Good where controllers validate inline or through FormRequests (360 tenant operations) | Generated |
| Response `data` | Typed inside the envelope in about 97% of operations since D-142 (BG-06), with `meta.pagination` and `meta.links` on paginated lists. Still `string`: the KPI strips (`*/metrics`), dashboard sections, landlord platform-settings groups and `PUT products/{product}/tags`. | Generated; overlay only for the untyped operations |
| Error responses | Every 4xx/5xx points at the `ErrorEnvelope` schema (D-142) | Generated type; errors are still normalised from the real envelope (§12.4) |
| Route manifest: permission, module, wind-down, idempotency, usage limit per route | `routes.{landlord,tenant}.json` from `php artisan frontend:contract` (D-142, BG-07) | Generated |
| Module, limit and permission registries | `modules.json`, `limits.json`, `permissions.*.json`, `error-codes.json` (D-142) | Generated |

**Decision (ADR-11): hybrid typing.**

- `openapi-typescript` generates `paths` for both documents. `openapi-fetch` uses them for URLs, parameters and bodies.
- Response types are generated from the same documents (BG-06, resolved in D-142). A small typed overlay in `packages/contract/src/responses/` covers only the operations whose `data` is still `string` (§13.1). It is mapped by operation ID, for example `'tenant.admin.projects.metrics': KpiStrip`.
- A full generated SDK is rejected. It would duplicate what `openapi-fetch` already gives with zero runtime.

### 13.2 The Response Overlay

- The overlay is written from the backend Resources and presenters, the source of truth for response fields. Every type carries a `/** @source App\Modules\...\ProductResource */` comment.
- A contract test suite (§35.3) runs against a seeded backend in CI. It records one real response per overlay entry and validates it against a Zod schema derived from the overlay (`zod` + `@workspace/contract/schemas`). Drift fails the contract job. Runtime validation is not done in production.
- `pnpm contract:gaps` lists overlay entries and fails when an entry's operation has become typed in OpenAPI, so the overlay shrinks as the backend improves. After D-142 it holds about 35 entries (the KPI strip shape is shared by all `*/metrics` routes).

### 13.3 Generation

- `packages/contract/bundle/` holds the pinned backend artefacts:
  - `landlord.openapi.json` and `tenant.openapi.json`;
  - `routes.{landlord,tenant}.json`, `modules.json`, `limits.json`, `permissions.{landlord,tenant}.json`, `error-codes.json` and `storefront.json` (themes, fonts).
- All of them come from one backend command (BG-07, resolved in D-142): `php artisan frontend:contract --path={absolute bundle path} [--openapi]`. `--openapi` re-exports the OpenAPI documents first. The bundle is not committed in the backend.
- `contract.lock.json` records the backend commit SHA and the SHA-256 of each file.
- `pnpm contract:pull --from ../tenant-ecommerce-api` runs that command into `packages/contract/bundle/` and updates the lock. It re-exports the OpenAPI documents from the current backend code (`frontend:contract --openapi`) by default; `--no-openapi` copies the last exported documents, which can be stale and hide new annotations.
- `pnpm contract:generate` writes `packages/contract/src/generated/**`: `paths` types, the `ModuleKey`, `LimitKey`, `Permission` and `RouteName` unions, the route manifest, and theme and font identifier lists.
- Generated output is committed, marked generated, and excluded from lint.
- CI regenerates and fails when the committed output differs (§38.3). Upgrading the contract is an ordinary pull request: bump, regenerate, fix type errors. The type errors are the list of affected screens.

### 13.4 Route Manifest (BG-07)

`routes.{landlord,tenant}.json` (D-142) gives, for each named API route:

| Field | Meaning |
|---|---|
| `name`, `methods`, `uri` | Laravel route name (= OpenAPI operation ID), methods without `HEAD`, URI with `/api` |
| `group` | Middleware group: `tenant.admin`, `tenant.public`, `tenant.customer`, `landlord.admin`, `landlord.affiliate` and the others |
| `actor`, `actor_optional` | Token actor from `auth.as` (`staff`, `customer`, `seller`, `driver`, `platform`, `affiliate`); `actor_optional` for storefront routes that accept a customer token |
| `module`, `module_notice` | Feature key from `feature:{key}` (null for core), and the `module.notice` key |
| `wind_down`, `read_when_inactive` | The route stays usable while its module is disabled or locked: wind-down routes always, admin `GET`s when the module reads while inactive |
| `permission`, `acting_permission` | The derived permission checked by `permission.derived` (null when the route opts out), and the permission a self-service route needs to act for someone else |
| `idempotency`, `usage_limit`, `signed` | `Idempotency-Key` required, the limit key checked before a create, a signed URL |

- `contract:generate` turns these into the `RouteName`, `Permission`, `ModuleKey`, `LimitKey` and `ApiErrorCode` unions and a typed manifest keyed by route name. Navigation entries and gated actions name a route, and their permission and module come from the manifest. A misspelt route name is a type error.
- **CI check.** Any permission, module or route literal written by hand outside the manifest must exist in the generated unions.

This keeps one module system and one permission system: nothing is invented, and every value comes from the backend.

---

## 14. State Management, Data Fetching and Caching

### 14.1 State Categories

| Category | Owner | Examples | Not allowed |
|---|---|---|---|
| Server state, server side | Next.js cache (`'use cache'`) and React `cache()` | Storefront catalogue, CMS, storefront config, platform website content | Caching any authenticated response |
| Server state, client side | TanStack Query | Admin lists and records, `me`, cart, account, inbox | Copying query data into other stores |
| URL state | nuqs, route segments | Filters, sort, page, search, tab, selected record, catalogue filters | Filters kept only in memory |
| Form state | React Hook Form | Edit forms, checkout steps | Global form stores |
| Local UI state | `useState`, `useReducer` | Dialog open, hover | Context without need |
| Feature-scoped shared UI state | Small React context inside one feature | Table selection for the bulk bar | App-wide feature contexts |
| Device preferences | `localStorage`, via a typed hook | Sidebar collapsed, table density, column visibility | Tokens, personal data, API responses |
| POS offline sale state | Zustand, persisted to IndexedDB | Current sale, offline queue | Anything outside POS |

Rules:

- No Redux, MobX, Recoil or app-wide Zustand store.
- Derive, don't store.
- Server data enters `useState` only as form default values.
- `localStorage` keys are namespaced `{app}:{host}:{key}`.

### 14.2 Where Data Is Fetched

| Situation | Mechanism |
|---|---|
| Storefront and website public data | Server Components calling cached data functions (§14.4) |
| Storefront personal data (cart, account, orders) | Client components with TanStack Query through the BFF. Account pages may prefetch on the server. |
| Admin first render | The page Server Component prefetches the main query with the server client and passes it through `HydrationBoundary`. |
| Admin interaction | TanStack Query in client components |
| Dashboards | One query per dashboard section (§22) |

### 14.3 Query Keys and Freshness

Each feature defines a key factory whose first element is the resource name. URL-dependent keys include the parsed URL-state object. Queries are declared with `queryOptions()`, so server prefetch, `useQuery` and `ensureQueryData` share one definition. The query client is created per request on the server and once per browser session. Admin apps are host-scoped, so keys need no tenant dimension.

| Data | `staleTime` | Refetch |
|---|---|---|
| Session snapshot | 5 minutes | Focus, every 5 minutes |
| Modules | 5 minutes | After module actions |
| Lookups (`/api/admin/lookups/{key}`, `/api/lookups/{key}`) | 30 minutes | Never on focus. These are small bounded lists (brands, categories, warehouses, roles…): the combobox loads the list once and filters locally. Large resources (products, customers, orders, suppliers) are searched on the server with `search` and `per_page`, debounced 300 ms (`EntityCombobox`). |
| Admin lists and records | 30 seconds | Focus |
| Dashboard sections | 60 seconds | Every 5 minutes while visible |
| Reports | 5 minutes | Manual refresh |
| Inbox first page | 30 seconds | Every 60 seconds while visible |
| Import and export status | 0 | Polling while running (§19.4) |
| Cart | 0 | After each cart mutation, and on focus |
| Checkout quote | 0 | On every address, shipping, coupon or currency change |

`gcTime` is 10 minutes. Polling stops when the tab is hidden (`refetchIntervalInBackground: false`).

**Mutations:**

- Invalidate the narrowest keys: the record's detail and the resource's lists. Cross-resource effects are invalidated explicitly (for example, approving a return invalidates its order).
- **Optimistic updates** are used only for certain, reversible results: activate and deactivate toggles, reordering, marking a notification read, wishlist add and remove.
- **Never optimistically update** money, stock, status transitions, module activation, or anything the API may refuse for business reasons.

### 14.4 Server Cache (Storefront and Website)

Every cached data function:

- takes `apiHost` as its first argument, so the host is part of the cache key. A custom ESLint rule rejects a `'use cache'` function in `src/server/data/` whose first parameter is not named `apiHost`.
- is marked `'use cache: remote'`, with explicit `cacheLife` and `cacheTag`;
- calls `upstream()` with `session: 'none'`, so a cached value can never contain personal data.

| Function | `cacheLife` (revalidate) | Tags |
|---|---|---|
| `getStorefrontConfig(apiHost)` | 300 s | `t:{host}:config` |
| `getMenu(apiHost, key)` | 300 s | `t:{host}:menus` |
| `getHome(apiHost)`, `getCmsPage(apiHost, slug)` | 300 s | `t:{host}:cms`, `t:{host}:cms:{slug}` |
| `getProduct(apiHost, slug, currency)` | 60 s | `t:{host}:products`, `t:{host}:product:{slug}` |
| `listProducts(apiHost, filters, currency)` | 60 s | `t:{host}:products` |
| `getCategory(apiHost, slug)`, `getBrand(apiHost, slug)`, trees | 300 s | `t:{host}:taxonomy` |
| `getBlogPost(apiHost, slug)`, `listBlogPosts(apiHost, query)` | 300 s | `t:{host}:blog` |
| `getPlans(apiHost, currency)`, `getPlatformConfig(apiHost)` (website) | 300 s | `l:plans`, `l:config` |

- **Shared handler.** `cacheHandlers.remote` is a Redis-backed handler shared by all replicas, so a revalidation reaches every replica (§38.5).
- **Time-based revalidation** is the baseline, and staff see changes within these TTLs. Admin publish buttons say "Live on your store within 5 minutes". BG-13 proposes backend-triggered tag revalidation through a private `POST /bff/internal/revalidate`.
- **Price staleness** of up to 60 seconds on listings is safe, because checkout always re-quotes and compares `quote_hash`.

---

## 15. Routing and URL State

### 15.1 Route Naming

- **Admin paths mirror API resource paths:** `GET /api/admin/{resource}` is `/{resource}`, and nested resources nest (`/api/admin/hr/employees` → `/hr/employees`). Records are `/{resource}/{id}`, `/{resource}/{id}/edit`, `/{resource}/new` and `/{resource}/{id}/{tab}`.
- Settings screens are grouped under `/settings/*` for usability (§27.2).
- **Paths the backend generates** (§3.8) are fixed exceptions: `/billing/callback`, `/exports/[export]`, `/imports/[import]`, `/reset-password`.
- Segments are kebab-case. Dynamic segments use the API parameter name (`[product]`, `[return]`).
- **Storefront and website paths are customer-facing contracts** shared with the backend (§3.8, `config/cms.php`). They never mirror the API.

### 15.2 Route Groups

| App | Groups |
|---|---|
| `platform-web` | `(marketing)`, `(signup)`, `oauth`, `bff` |
| `platform-admin` | `(auth)`, `(console)`, `bff` |
| `affiliate-portal` | `(auth)`, `(portal)`, `bff` |
| `tenant-admin` | `(auth)`, `(staff)`, `seller/(auth)`, `seller/(portal)`, `pos`, `kitchen`, `bff` |
| `storefront` | `(shop)`, `(checkout)`, `account`, `auth`, `bff` |

### 15.3 URL State Rules

1. Every list filter, sort, page, page size, search term and visible tab is in the URL, parsed with nuqs parsers defined next to the feature's key factory.
2. Parameter names are the API's query names (`status`, `from`, `to`, `search`, `sort`, `page`, `per_page`, `cf[key]`), so the parsed object is passed to the API unchanged.
3. Defaults are omitted (`clearOnDefault: true`).
4. Changing a filter resets `page` to 1.
5. Admin search updates the URL with `throttleMs: 300` and `shallow: true`. Storefront filters use `shallow: false`, so the Server Component re-renders from cached data.
6. Transient UI (an open dialog, hover) is not in the URL. A quick view of a shareable record uses a parameter (`?order=123`).
7. Invalid URL values fall back to defaults and never throw.

### 15.4 Redirect Rules

- `next` parameters are validated as same-origin relative paths (§9.2).
- Access-state pages keep the requested URL, so the user returns after enabling a module or upgrading.
- **Storefront host canonicalisation.** A page request on a non-primary domain is redirected with 308 to the config's `tenant.primary_domain` (§29.9).
- **Storefront `/admin` and `/admin/*`** redirect with 308 to `https://{tenant.slug}.admin.ROOT/…`, for people who type the store address followed by `/admin`. The backend's own links already point at the admin host (D-141).

---

# Part III: Shared Application Architecture

## 16. Feature Architecture Inside an App

### 16.1 App Layout

```
apps/{app}/src/
  app/          Next.js routes only: page.tsx, layout.tsx, loading.tsx, error.tsx, not-found.tsx, route.ts
  features/     one folder per feature (§16.2)
  shell/        providers, navigation registry assembly, banners, access hooks
  server/       server-only: API client wiring, cached data functions (storefront, website)
  config/       bff.config.ts, navigation groups, constants
  env.ts        validated environment (§37)
  proxy.ts      host validation and host redirects (§31.6)
```

`app/` files are thin. A page imports a page component from a feature and passes route params and parsed search params. Its only data logic is server prefetching.

### 16.2 Feature Folder

```
features/returns/
  index.ts             public surface: page components, nav entries, pickers other features may use
  feature.ts           moduleKey (or null) and permission constants used by this feature
  api/
    keys.ts            query key factory
    queries.ts         queryOptions() definitions
    mutations.ts       useMutation hooks with invalidation
    search-params.ts   nuqs parsers and the list filter type
  components/          feature UI
  forms/               RHF forms and their Zod schemas
  pages/               page components rendered by app/ routes
  nav.ts               navigation entries (admin apps)
  routes.ts            typed href builders: returnsRoutes.detail(id)
  __tests__/
```

### 16.3 Feature Rules

1. A feature folder corresponds to one backend code module, in kebab-case (`Returns` → `returns`, `Integrations\WooCommerce` → `woocommerce`). A backend module that hosts several feature keys (`Hr` hosts `hr`, `hr_payroll` and `hr_recruitment`) is one folder with sub-folders per key. §40.1 is the full map.
2. Other features and `app/` import a feature only through its `index.ts`.
3. A feature may import another feature's public surface only for composition, for example the orders feature uses `CustomerPicker`. Circular imports are rejected.
4. Features never read `process.env`.
5. Features are not registered at runtime. Navigation and routes are static code, and visibility is computed from the snapshot. A plugin system would be a second module system.
6. A feature for an optional module runs no code when its module is not readable, apart from its access-state page. Because its routes and navigation are hidden, its chunk is never downloaded by a tenant that cannot read it.
7. Heavy libraries (Tiptap, Recharts, barcode scanning, Echo) are imported only in the features that need them, with `next/dynamic` for components below the fold.

---

## 17. Admin Shell and Navigation Registry

### 17.1 Shell Composition

`packages/admin-kit` provides the shell. `platform-admin`, `tenant-admin` (staff and seller shells) and `affiliate-portal` supply their registry, branding and banners.

| Region | Content |
|---|---|
| Sidebar | Registry groups filtered by the snapshot; an "Inactive modules" group; collapse state in `localStorage`. Built on the `sidebar` component in `ui`. |
| Header | Breadcrumbs, command palette trigger, notification bell (§21.1), and user menu (profile, preferences, password, logout) |
| Banner stack | In priority order: tenant or subscription restriction, platform or module maintenance, module notice for the current module, test payment mode (`payment_mode = test` in `me`), legal re-acceptance, trial ending |
| Content | The page, inside an error boundary and a Suspense boundary |
| Toasts | The `toast` region in `ui` |

The seller and affiliate shells use the same component, with their own registry, a simpler header and no command palette.

### 17.2 Registry Entry and Visibility

```ts
type NavEntry = {
  id: string;             // stable, kebab-case
  label: string;
  icon: IconName;         // from @workspace/ui/icons
  href: string;           // from the feature's routes.ts
  route: RouteName;       // the Laravel route behind the page, e.g. 'tenant.catalog.admin.products.index'
  group: NavGroupId;
  order: number;
  children?: NavEntry[];
  keywords?: string[];    // command palette
};
```

The module key and permission are read from the route manifest for `route` (§13.4). They are never repeated in the entry.

Visibility, for each entry:

1. **Missing route.** A route missing from the manifest fails a generated test.
2. **Enabled.** Module `enabled` (or no module) and the permission held (or none): show it in its group.
3. **Inactive.** Module `disabled` or `locked`, read-when-inactive, and the permission held: show it in "Inactive modules" with a state badge.
4. **Otherwise** hide it.
5. **Empty parent.** A parent with no visible children is hidden.

This is the whole abstraction: a typed array per app and one pure function in `admin-kit`. Features contribute entries through `nav.ts`, and the app's `shell/nav.ts` concatenates them.

### 17.3 Command Palette

The palette is built from:

- the visible registry entries (labels and keywords);
- **Create** actions for store routes the user can call;
- the 10 most recently visited records (`localStorage`, per host).

It does not query the API on open. A "Search records" section calls the products, orders and customers list endpoints with `search`, after 2 characters and a 300 ms debounce.

---

## 18. CRUD, Data Tables, Forms and Custom Fields

### 18.1 Page Templates (`admin-kit`)

| Page | Composition |
|---|---|
| List | `ListPage`: title, gated primary action, metrics strip when the resource has `/metrics` (§22.2), filter bar, `DataTable`, bulk bar when a bulk route exists, import and export buttons when the resource has an import or export type, empty state, usage meter for limited resources |
| Detail | `DetailPage`: header with status badge and actions, tabs, summary panel, custom-field panel (`show_on_detail`), and an activity or audit tab where the API provides one |
| Create, Edit | `FormPage`: form sections, sticky footer with Cancel and Save, dirty-state guard |
| Settings | `SettingsPage`: a sectioned form, with one save per section |
| Simple resources (fewer than 6 fields: tags, units, customer groups, return reasons, expense and income categories, purchase-return reasons, project categories) | A dialog form on the list page |

### 18.2 Actions

- **Status transitions** (approve, cancel, finalise, ship, publish) are explicit actions with a confirmation that states the consequence. They are never field edits. A 422 `invalid_transition` refetches the record and explains that its state changed.
- **Destructive actions** name the record in the confirmation. Delete is offered only where the API has a `DELETE` route. Where the API offers deactivation instead (products, users, custom fields, plans, coupons), the UI offers deactivation, plus reactivation where a route exists.
- **Confirmation surface.** Confirmations use `ConfirmDialog` from `@workspace/ui`. With `variant="auto"` (the default) it is a bottom drawer on mobile and an alert dialog elsewhere; `"dialog"` or `"drawer"` forces one. On a drawer the primary action sits above Cancel. It cannot be dismissed while the action is pending.
- **Save behaviour:**
  - send only the fields the typed body accepts, including `custom_fields`;
  - on 201, navigate to the new record; on 200, stay and toast;
  - on 422, map field errors and scroll to the first;
  - on 409, keep the form and refetch (§14.3);
  - on a 403 module or limit error, keep the form and open the dialog.

### 18.3 Data Table

`admin-kit` `DataTable` wraps TanStack Table in manual mode:

```tsx
<DataTable
  columns={columns}                     // ColumnDef<T>[], plus customFieldColumns(definitions)
  query={productListQuery(filters)}     // queryOptions → Page<T>
  filters={productFilters}              // filter bar descriptors
  searchParams={productSearchParams}    // nuqs parsers
  rowActions={(row) => …}
  bulk={{ route: 'tenant.catalog.admin.products.bulk', actions: productBulkActions }}
  exportType="products"                 // §19.3
  importType="products"                 // §19.2
  emptyState={<ProductsEmpty />}
/>
```

| Concern | Rule |
|---|---|
| Pagination | Server-side. `page` and `per_page` in the URL; the total from `meta.pagination.total`. Cursor lists show **Load more**. |
| Sorting | Only columns whose route documents a `sort` value. One sort column. |
| Filtering | The filter bar emits API query parameters directly: text search, select, multi-select, date range (`from`, `to`), boolean, number range and custom-field filters (`cf[key]`). |
| Column visibility | User-toggleable, stored per table and host |
| Selection | Only when a permitted bulk route exists. Per page; it clears on page or filter change. There is no "select all matching", because bulk routes accept at most 100 ids. |
| Loading | Skeleton rows first, then previous data with a progress bar (`keepPreviousData`) |
| Empty | "No records yet" (with create) is distinct from "No results for these filters" (with Clear filters). |
| Errors | Inline error with retry. Access errors render the access state. |
| Responsive | Below 768 px, rows render as cards showing the primary columns. |
| Density | Comfortable or compact, stored per device |

Tables never sort or filter client-side, never fetch every page to compute totals (totals come from `/metrics` or reports), and never inline-edit money, stock or status.

### 18.4 Forms

**Stack.** React Hook Form with `zodResolver`, rendered with the `Field`, `FieldLabel`, `FieldDescription` and `FieldError` components from `ui`.

**Validation split:**

| Layer | Validates |
|---|---|
| Zod (client) | Type shape, required fields, universal formats (email, URL), and the length and range limits published in the OpenAPI parameter schemas |
| Laravel (server) | Everything, authoritatively: uniqueness, business and cross-field rules, permission-dependent fields |

A client rule that disagrees with the server is a defect in the client rule. Client schemas never encode business rules such as price floors, stock availability or allowed transitions.

**`applyApiErrors(form, error)`** (in `admin-kit`, also used by the storefront):

1. For each key in `error.fieldErrors`, it calls `form.setError(key, {type: 'server', message})`. Laravel dot notation (`items.2.quantity`, `custom_fields.warranty_months`) is also RHF's path notation.
2. Keys that match no registered field go into a form-level alert, so no server message is lost.
3. Focus moves to the first field with an error.

**Conventions:**

- Money inputs produce decimal strings at the currency's precision (`decimal_digits` from settings).
- Quantity inputs produce up to 3 decimals.
- Date-times are shown in the tenant timezone and sent as ISO 8601 with offset.
- Relationship fields use `Combobox` with server search against the lookup or list endpoint.
- Submit buttons show pending state and cannot double-submit.
- Unsaved changes are guarded on navigation.
- Multi-step forms (checkout, signup, product with variants) keep one RHF instance per flow, with the step in the URL.

### 18.5 Custom Fields

**Scope.** Tenant only.

- Core entities: `product`, `product_variant`, `customer`, `order`, `shipment`, `order_return`, `warehouse`.
- Module-owned entities (each with the module key listed in `GET /api/admin/custom-fields/entities`): `supplier`, `purchase_order`, `sales_quotation`, `expense`, `employee`, `seller`, `project`, `work_order`, `booking`, `repair_job`.

**`packages/custom-fields`** exports:

| Export | Purpose |
|---|---|
| `buildCustomFieldsSchema(definitions)` | A Zod object for the `custom_fields` value, from `field_type`, `is_required` and `validation` |
| `<CustomFieldInput definition />` | The input for one definition inside an RHF context |
| `<CustomFieldsSection definitions />` | Active definitions with `show_on_form`, in `sort_order` |
| `<CustomFieldValue definition value />` | Read-only display for detail pages, tables and the storefront |
| `customFieldColumns(definitions)` | Columns for `show_in_table` fields |
| `customFieldFilters(definitions)` | Filter descriptors for `is_filterable` fields |

**Type renderers:**

| Type | Input | Display | Value sent |
|---|---|---|---|
| `text` | `Input` (`min_length`, `max_length`, `pattern`) | Text | string |
| `textarea` | `Textarea`; plain text, never HTML | Text with line breaks | string |
| `number` | Integer input (`min`, `max`) | Locale number | integer |
| `decimal` | Decimal input (`decimal_places`) | Locale number | decimal string |
| `currency` | Money input in the base currency | Formatted money | decimal string |
| `boolean` | `Switch` or `Checkbox` per `display` | Yes or No | boolean |
| `date` | Date picker (`min_date`, `max_date`, `not_in_past`, `not_in_future`) | Tenant date format | `YYYY-MM-DD` |
| `datetime` | Date-time picker in the tenant timezone | Tenant date-time | ISO 8601 |
| `select` | `Select` or `RadioGroup` | Option label | option value |
| `multi_select` | Multi-select or checkboxes (`min_selected`, `max_selected`) | Chips | option values |
| `url`, `email`, `phone` | Typed inputs; phone normalised to E.164 | Link, `mailto:`, `tel:` | string |
| `file`, `image` | Picker with `allowed_extensions` and `max_size_kb` checks | Name with a signed download link, or a thumbnail | Uploaded separately (below) |

An unknown type renders a read-only "Unsupported field" notice and is omitted from the submitted value.

**Where definitions come from:**

| Surface | Source |
|---|---|
| Admin forms, details, tables | `GET /api/admin/custom-fields?entity_type=&is_active=1`, cached per entity for 5 minutes |
| Storefront registration and checkout | `GET /api/storefront/custom-fields?entity_type=customer\|order` (public fields only) |
| Storefront product page | The product resource's `custom_fields` |

**File and image values.** Files are uploaded through the entity's media endpoint with `collection=custom_fields` and `field_key`, and served through `GET /api/custom-field-files/{media}`.

- On create, the form saves the entity, then uploads files, then navigates. Upload failures are reported per field on the detail page with a retry.
- On edit, a file uploads on selection.
- The storefront does not render public `file` or `image` fields on registration or checkout, because customers have no media endpoint for `customer` or `order`.

**Definition editor.** `tenant-admin` `/settings/custom-fields`:

- an entity selector (inactive modules are read-only);
- a sortable list per entity (`PUT /api/admin/custom-fields/reorder`);
- create and edit forms, where `key` and `field_type` are locked after creation;
- delete only when a definition has no values, otherwise deactivate; 422 `custom_field_has_values` is handled;
- a usage meter for `max_custom_fields`.

---

## 19. Bulk Actions, Imports, Exports and Asynchronous Operations

### 19.1 Bulk Actions

**Backend contract (D-133).** Bulk actions are synchronous. `POST /api/admin/{resource}/bulk` takes `{action, ids, …action fields}` with at most 100 ids. Each item runs through the single-item service independently, and there is no rollback. The response `data` is `{operation_id, succeeded, failed, results: [{id, status: 'ok'|'error', error, message}]}`.

| Resource | Route | Actions |
|---|---|---|
| Products | `products/bulk` | `activate`, `deactivate` |
| Coupons | `coupons/bulk` | `activate`, `deactivate` |
| Customers | `customers/bulk` | `activate`, `deactivate`, `assign_group` (+ `customer_group_id`) |
| Categories | `categories/bulk` | `activate`, `deactivate` |
| Orders | `orders/bulk` | `processing`, `delivered`. Orders outside the viewer's warehouses fail as not found. |
| Reviews | `reviews/bulk` | `approve`, `reject` (+ one `reason`) |

**UX** (`admin-kit` `BulkBar`):

1. Row selection appears only when the bulk route is permitted (`canRoute`). A header checkbox selects the current page. There is no cross-page "select all", which the 100-id cap rules out.
2. The bulk bar shows "{n} selected", the permitted actions and **Clear**. It sticks to the bottom of the table.
3. A confirmation states the action and the count. Actions that need input (group, reason) collect it in the dialog.
4. While the request runs, the bar shows a progress state and disables the actions. The request is a single synchronous call and cannot be cancelled.
5. The result dialog shows "{succeeded} succeeded, {failed} failed". Each failure is listed with the item's display name (looked up from the current page data) and its `message`. Successful items are not rolled back. **Copy failed ids** and **Retry failed** re-run the action on the failed ids only.
6. Afterwards the app invalidates the resource's lists and the affected details, and clears the selection.
7. `operation_id` is shown in the dialog footer, because staff can quote it to support. It is the key of the backend `bulk_actions` activity row.

### 19.2 Imports

**Backend contract (D-135).**

| Endpoint | Purpose |
|---|---|
| `GET /api/admin/imports/types` | The types the user may import: categories, products, customers, stock (modes `adjust`, `set`), with columns and modes |
| `GET /api/admin/imports/types/{type}/template` | A CSV template (download) |
| `POST /api/admin/imports` (multipart: `import_type`, `file` CSV or XLSX ≤ 10 MB, `mode`: `upsert` (default), `create`, `update`) | 202 with the import |
| `GET /api/admin/imports` | The caller's imports |
| `GET /api/admin/imports/{import}` | `{id, import_type, mode, status, file_name, processed_rows, created, updated, failed, error, created_at, started_at, completed_at}` |
| `GET /api/admin/imports/{import}/errors` | Paginated rejected rows `{row, field, message, value}` |

- **Statuses:** `queued`, then `processing`, then `completed`, `completed_with_errors` or `failed`.
- **Notification:** `import.completed`.
- **Rows:** a rejected row does not stop the others. Rows commit individually with a checkpoint, so a retried job never applies a row twice.
- **No preview, no dry run, no column mapping.** Headings must match the template.

**UX flow** (`admin-kit` `ImportDialog` and `ImportsPage`):

```
Choose type (from /imports/types, filtered by permission)
  → Download template (optional)
  → Choose mode (default upsert; stock shows adjust or set)
  → Pick file (CSV/XLSX, ≤ 10 MB, checked client-side)
  → Upload with progress (XMLHttpRequest, §20.1)
  → 202: navigate to /imports/{id}
  → Status page polls GET /imports/{id} every 3 s while queued or processing
       (backs off to 10 s after 1 minute; stops when the tab is hidden)
  → Terminal state:
       completed              → counts, links to the imported resource list
       completed_with_errors  → counts plus the paginated error table (row, field, message, value),
                                with "Download errors as CSV" generated client-side from the fetched pages
       failed                 → the error message, with "Import another file"
```

- **Entry points:** the list page's **Import** button, for resources with a type, and `/imports`, the history page with each import's status.
- **Upload errors:**
  - 422 on the file shows inline;
  - 403 `forbidden` explains the permission the type needs (`categories.create`, `products.create`, `customers.create`, `stock-adjustments.create`);
  - 403 `limit_reached` opens the limit dialog.
- **Retry:** a failed import is fixed and re-uploaded as a new import. There is no retry endpoint.

### 19.3 Exports

**Backend contract (D-134).**

- **Endpoints:** tenant `POST /api/admin/exports {export_type, format, parameters}` returns 202. `GET /api/admin/exports`, `GET …/{export}` and `GET …/{export}/download` follow. Platform exports use the same paths on the landlord host.
- **Formats:** `csv`, `xlsx` (≤ 50,000 rows; beyond that the export fails and asks for CSV) and `json`. Reports also offer `pdf`.
- **Download:** a 302 to a 5-minute temporary URL (S3), or a streamed file (local disk).
- **Retention:** 7 days.
- **Deduplication:** a duplicate request returns the existing export.
- **Notifications:** `export.ready` (tenant) and `platform.export_ready` (landlord).
- **Tenant types:** `customers`, `products`, `product_variants`, `categories`, `inventory`, `orders`, `order_items`, `order_payments`, `shipments`, `returns`, `coupons`, and the reports.
- **Platform types:** `tenants`, `subscriptions`, `payment_transactions`, `affiliates`, `affiliate_payouts`.

**UX** (`admin-kit` `ExportButton` and `ExportsPage`):

1. **Export** on a list page opens a dialog with the format (xlsx is the default for lists, pdf is offered only for reports) and the current URL filters as `parameters`. The dialog states "Exports run in the background. You'll be notified when it's ready."
2. On 202 the toast reads "Export queued" and links to `/exports/{id}`, the backend's email link target. If the export already exists, the toast says so and links to it.
3. `/exports` lists the caller's exports with status, format, row count, created and expiry dates. Queued and processing rows poll every 5 seconds (10 seconds after a minute, for up to 10 minutes). Completed rows show **Download**.
4. **Download** is a plain link to `/bff/staff/api/admin/exports/{id}/download`. The BFF passes the 302 or the stream through, so the browser downloads directly and the file never enters JavaScript memory.
5. Expired exports show "Expired" and **Export again**, which reuses the stored `parameters`. Failed exports show the backend's message and **Try again**.

**Customer data export** (storefront, `/account/privacy`):

- `POST /api/account/export` queues the export.
- The email links to `/account/export?link={signed}`. That page shows a **Download** button pointing at the signed `GET /api/exports/{export}/download` URL on the same host, which the edge routes to Laravel.

### 19.4 Asynchronous Operations

The backend queues imports, exports, tenant exports, tenant provisioning, payment verification and integration syncs, and none of these broadcast (§3.7). Every async operation therefore follows one pattern, `admin-kit` `useOperationStatus`:

| Aspect | Rule |
|---|---|
| Start | The mutation returns 202 with a resource that has `status`. The UI navigates to, or opens, that resource's status view. |
| Status | Polling `GET` on the resource: 3 seconds for the first minute, then 10 seconds, stopping on a terminal status, after 10 minutes, or when the tab is hidden (and resuming on focus). |
| Terminal states | Rendered from the resource's own status vocabulary. No frontend state machine is added. |
| Failure | The backend `error` or `message`, with the operation's own retry path |
| Cancellation | Not offered. No queued operation has a cancel endpoint. |
| Notification | Completion also arrives in the notification inbox (§21.1), which covers users who leave the page. |
| Realtime | Not used. There are no broadcasts. If the backend later broadcasts status events, the hook subscribes and polling becomes the fallback (§21.2). |

| Operation | Start | Status | Terminal |
|---|---|---|---|
| Import | `POST /api/admin/imports` | `GET /api/admin/imports/{id}` | `completed`, `completed_with_errors`, `failed` |
| Export (tenant, platform) | `POST …/exports` | `GET …/exports/{id}` | `completed`, `failed` (expiry via `expires_at`) |
| Tenant full export (platform) | `POST /api/admin/tenants/{tenant}/export` | Tenant detail and notification | Download link `GET /api/tenant-exports/{file}` |
| Signup provisioning | Registration | `GET /api/register/{registration}/status` every 15 s (§24.3) | `active` |
| Storefront payment | Gateway return | `POST /api/payments/verify`, then order polling (§29.7) | `successful`, `failed` |
| POS terminal charge | `POST /api/admin/pos/terminal-charges` | `GET …/terminal-charges/{reference}` every 2 s | Terminal charge status |
| WooCommerce and social sync | Sync actions | Sync run lists and `/sync/metrics` | Run status |

---

## 20. Files and Media

### 20.1 Uploads

- **Transport.** Files go as `multipart/form-data` through the BFF, which streams the body without buffering.
- **Client checks.** Type (MIME and extension) and size are checked for fast feedback, using the limits the API documents for the collection. Laravel sniffs content and enforces the real limits.
- **No SVG.** SVG is never offered in image pickers; the backend rejects it.
- **Progress.** `api-client` `uploadWithProgress()` uses `XMLHttpRequest`, because `fetch` has no upload progress events. It uses the same BFF path, CSRF header and error normalisation.
- **Size limit.** The edge, the Next.js route handler and Laravel share one maximum body size (`MAX_UPLOAD_MB`, 25).
- **Storage limit.** A 403 `limit_reached` on `max_storage_mb` opens the limit dialog.

### 20.2 Where Uploads Go

| Media | Endpoint |
|---|---|
| Product gallery, featured image, reorder, delete | `POST /api/admin/products/{product}/media`, `…/media/reorder`, `…/media/{media}/featured`, `DELETE …/media/{media}` |
| Brand, category image | `POST /api/admin/brands/{brand}/image`, `…/categories/{category}/image` |
| Tenant CMS images | `POST /api/admin/cms/pages/{page}/image`, `…/pages/{page}/media`, `…/blog-posts/{post}/image`, `…/banners/{banner}/image`, `…/testimonials/{testimonial}/image` |
| Landlord CMS images | The same shapes under the landlord `/api/admin/cms/*` |
| Seller product media | `/api/seller/products/{product}/media*` |
| Digital product files | Product digital-file routes (admin and seller) |
| Employee documents | `POST /api/admin/hr/employees/{employee}/documents` |
| Return photos, support attachments, job application résumé | Multipart on their create routes |
| Custom-field files | Entity media endpoint with `collection=custom_fields` (§18.5) |
| Store logo, favicon, share image (`*_media_id` settings), variant image, platform logo | `POST /api/admin/settings/media` (`setting` + `image`) and `DELETE /api/admin/settings/media/{setting}`; `POST`/`DELETE /api/admin/products/{product}/variants/{variant}/image`; landlord `POST`/`DELETE /api/admin/platform-settings/media` (BG-12, resolved in D-142). The settings screens upload in place and show the returned `url`. |

### 20.3 Public and Private Files

- **Public media** URLs come from the API. They are served from `cdn.ROOT` in production, or from `APP_URL/storage/media` locally. Images render with `next/image` using a custom loader that requests the backend's pre-generated conversions, not the Next image optimiser (§32.3).
- **Private files** (employee documents, résumés, digital downloads, support attachments, exports, custom-field files) are only ever displayed or downloaded through API routes that stream them or redirect to a temporary URL valid for at most 5 minutes. Those URLs are requested when the user acts, and are never cached, stored or placed in a shareable URL.
- **Download links** point at the BFF path, so the redirect or stream reaches the browser directly.

---

## 21. Notifications and Realtime

### 21.1 Notification Inboxes

| App | Inbox | Preferences |
|---|---|---|
| `tenant-admin` bell and `/notifications` | `GET /api/admin/notifications` (paginated, `unread` filter, `meta.unread_count`), `POST /api/admin/notifications/{id}/read`. Rows have `source`: `store` or `platform` (D-137). | `/settings/notifications/me`: `GET` and `PATCH /api/admin/notification-preferences` (`template_key`, `channel`, `enabled`), `POST …/reset` |
| `tenant-admin` templates | `/settings/notifications`: templates (`GET`, `PATCH`, reset) and the channel matrix | n/a |
| `storefront` `/account/notifications` | `GET /api/notifications`, `POST /api/notifications/{id}/read` | `GET`, `PATCH /api/notification-preferences`, `POST …/reset` |
| `affiliate-portal` | `GET /api/affiliate/notifications`, `POST …/{id}/read` | none |
| `platform-admin` | **None** (BG-08). Platform users get email only. | Notification templates and matrix (`/notification-templates`, `/notifications/matrix`) |

Behaviour:

- **Polling.** The bell fetches the first page every 60 seconds while the tab is visible, and shows `meta.unread_count` (capped at "99+").
- **Mark all read** is not offered until BG-09 ships.
- **Reading.** Clicking a notification marks it read (optimistic) and navigates to the resource referenced in `data` when there is one, for example `import_url` or an export id. Platform-sourced notices show a "Platform" badge.
- **Mandatory templates.** A preference `PATCH` on a mandatory template returns 422, and the UI renders those rows locked.
- **No toasts for polled notifications.** The badge is the signal.

### 21.2 Realtime

Realtime is used only where the backend broadcasts. It adds value there, because support chat needs sub-second delivery and typing indicators.

| Surface | Channel | Auth route (via `/bff/broadcasting/auth`) |
|---|---|---|
| `tenant-admin` support conversation | `private-tenant.{tenantId}.support-conversation.{id}` | Tenant `POST /api/broadcasting/auth` (staff) |
| `tenant-admin` support inbox | `private-tenant.{tenantId}.support-inbox` | as above |
| `tenant-admin` agents online | `presence-tenant.{tenantId}.support-agents-online` | as above |
| `storefront` support widget and `/account/support` | `private-tenant.{tenantId}.support-conversation.{id}` | Tenant `/api/broadcasting/auth` (customer) or `/api/support/broadcasting/auth` (guest, with the guest token) |
| `platform-admin` helpdesk | `private-platform-support-conversation.{id}` | Landlord `POST /api/broadcasting/auth` |
| `tenant-admin` platform support page | `private-platform-support-conversation.{id}` | Tenant `/api/broadcasting/auth` (staff) |

`packages/realtime`:

```ts
createEcho({ key, host, port, scheme, authEndpoint: '/bff/broadcasting/auth' });
// Event names are the backends' broadcastAs() values: tenant support
// '.message.sent' and '.conversation.status'; platform support '.message.sent'
// and '.conversation.status_changed'.
useChannel(name, { events: { '.message.sent': onMessage, '.conversation.status': onStatus } });
usePresence(name);
useWhisperTyping(name);   // at most one whisper per 2 seconds
```

- **Lazy connection.** Echo connects when the first realtime component mounts and disconnects when the last unmounts.
- **Channel names** are built from the documented patterns (§3.7) with the tenant id from the storefront config or staff `me` (`tenant.id`, D-141). BG-05 would move the names onto the resources themselves.
  The id is never taken from user input or the URL.
- **Gap filling.** On subscribe and on every reconnect, the conversation query refetches the newest messages. Events merge into the TanStack cache by message id, so there are no duplicates and no losses.
- **Fallback.** On a 403 from auth, or after 3 failed reconnects, the hook falls back to polling every 10 seconds.
- **Not realtime:** notifications, dashboards, orders, stock, POS and kitchen. They poll at the intervals stated in their sections.

---

## 22. Dashboards and Metrics

### 22.1 Section Dashboards

The backend serves dashboards per section with one KPI shape (UD-39):

- **Landlord:** `GET /api/admin/dashboard` (section list) and `GET /api/admin/dashboard/{section}`. Sections are `overview`, `tenants`, `subscriptions`, `revenue`, `plans`, `payments`, `affiliates` and `operations`.
- **Tenant:** the same paths on the tenant host. Sections are:
  - `overview`, `sales`, `orders`, `customers`, `catalogue`, `inventory`, `promotions`, `payments`, `returns`;
  - module sections: `accounting`, `expenses`, `pos`, `purchasing`, `gift_cards`, `reward_points`, `hr`, `manufacturing`, `restaurant`, `booking`, `repair`, `projects`, `support`, `installments`, `product_subscriptions`, `marketplace`, `sales_quotations`, `sales_agents`.
- **Query:**
  - `range`: one of `today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month`, `this_quarter`, `this_year`, `last_year` or `custom` (with `from` and `to`);
  - `compare`: `previous_period`, `previous_year` or `none`;
  - `interval`: `auto`, `hour`, `day`, `week` or `month`;
  - optional `currency`.
- **Response:** `data = {section, range, comparison_range, kpis[], charts[], tables[], alerts[]}` and `meta = {generated_at, cached}`.
  - Each KPI carries `key`, `label`, `value`, `format`, `currency_code`, `is_estimated`, `supporting_label`, `sparkline` and `comparison`.
  - `comparison` is `{value, from, to, change_percent, direction, sentiment}` or `null`.
  - Money KPIs come per currency, plus a combined estimate when rates exist.

**Architecture:**

- `/dashboard` renders tabs from the section list. The section list endpoint already filters out sections whose module the tenant cannot read.
- **One request per visible section.** The active tab fetches its section. Other tabs fetch when selected. No page issues more than 3 dashboard requests on load.
- **Range selector:** `range`, `from`, `to` and `compare` in the URL (nuqs), shared by all sections on the page (`admin-kit` `RangeControl`).
  - One button opens every preset plus a range calendar for `custom` (days after today are disabled, at most 731 days). A separate select sets `compare`.
  - An incomplete or invalid custom range in the URL falls back to `last_30_days`.
  - Under the tabs, the page states the resolved dates from `data.range` and `data.comparison_range` ("Showing … compared with …").
- **Range semantics to keep visible** (from `DateRange::fromInput`):
  - `last_7_days` and `last_30_days` are complete days and **end yesterday**, so today's activity is not in them. `today` and the `this_…` presets run up to now.
  - A range that ends before today is cached for an hour; one that includes today, for five minutes (`MetricsCache`).
- **Live or test money (landlord).** Subscription, MRR, revenue, payment, trial-conversion and plan figures read one billing mode, chosen by `mode=live|test` (default `live`), which is part of the cache key and echoed in `data.range.mode`.
  - The MRR ledger records both modes in `subscription_mrr_movements.mode` (the subscription's `gateway_mode`). `is_first_paid_charge` is scoped to the charge's mode; affiliate commissions stay live-only because `AffiliateCommissionService` checks the mode itself.
  - Alerts and the daily snapshot job stay live. Tenant counts include every tenant.
  - `php artisan billing:backfill-test-mrr` adds the missing "new" movement for test subscriptions paid before the ledger recorded test billing (idempotent; `--dry-run` lists them).
  - The platform dashboard has a **Live data / Test data** switch (`mode` in the URL). Test data is always flagged ("Showing test data"). While billing is in test mode, the live view offers the switch.
- **Rendering.**
  - `admin-kit` `KpiGrid` renders `KpiValue`s: value, `comparison.change_percent` coloured by `comparison.sentiment` (positive, negative, neutral), sparkline, and an "Estimated" badge when `is_estimated`. The frontend never computes the change or its sentiment.
  - Charts use the shadcn `chart` wrapper and are loaded with `next/dynamic`.
  - `alerts` render as a list above the KPIs, linking to the filtered list they describe.
- **Freshness:** `staleTime` 60 seconds, a refresh every 5 minutes while visible, and a manual refresh button. `meta.generated_at` is shown ("Updated 2 minutes ago").
- **States:**
  - loading uses a skeleton grid with the section's KPI count;
  - an empty section shows "No activity in this period";
  - an error shows an inline retry;
  - a section refused by module state shows the module state card.

### 22.2 Contextual Metrics Strips

List pages whose resource has a `/metrics` route show a KPI strip above the table. The strip uses the same `KpiGrid` and the page's range selector (default `this_month`).

- **Landlord resources:** tenants, subscriptions, payment transactions, platform coupons, affiliates, affiliate commissions, affiliate payouts.
- **Tenant resources:** products, categories, customers, orders, order payments, returns, reviews, shipments, coupons, promotions, gift cards, expenses, purchase orders, stock transfers, inventory, projects, product subscriptions, sales quotation requests, sellers, support conversations, kitchen, WooCommerce sync, social commerce sync, HR employees.

### 22.3 Reports

`tenant-admin` `/reports/{reportKey}` renders the `advanced_reporting` and core reports (`/api/admin/reports/*`: sales, purchases, payments, stock, profit by dimension, customers, suppliers, users, activity log, and more) as a filter form plus a server-paginated table. Every report offers **Export**, which calls the export centre with the report key and filters, including `pdf` (§19.3). Accounting reports (`/api/admin/accounting/reports/*`) render in the accounting feature.

---

## 23. Design System and Theming

### 23.1 `packages/ui`

```
packages/ui/src/
  styles/globals.css     Tailwind v4 entry, @theme inline tokens, dark variant (existing)
  components/            shadcn base-nova components (existing ~60) + composed components:
                         money-input, quantity-input, phone-input, file-dropzone, image-uploader,
                         status-badge, stat-card, key-value-list, page-header, confirm-dialog,
                         copy-button, stepper, rich-text-viewer, empty-state (extends `empty`)
  icons/index.ts         HugeIcons facade: the only import point for icons
  hooks/                 use-mobile (existing), use-media-query, use-disclosure, use-debounced-value
  lib/utils.ts           cn() = twMerge(clsx(...))
```

Rules:

1. `ui` has no data fetching, no API types, no Next.js imports and no app knowledge. Links are composed in through Base UI's `render` prop, for example `<Button render={<Link href="/orders" />}>`.
2. Components expose composition (slots, children), not dozens of props.
3. Every interactive component uses a Base UI primitive or a native element.
4. Colours are used only through semantic tokens (`bg-primary`, `text-muted-foreground`). Raw palette classes are rejected in apps (§5.6).
5. New components are added with the shadcn CLI into `packages/ui` (`pnpm dlx shadcn@latest add x -c apps/tenant-admin` writes into `ui` through the aliases), then adapted. Apps never keep their own copies.
6. Each component has a Testing Library render test with axe (§35.2). The non-production route `/ui` in `tenant-admin` renders every component state for visual review.

### 23.2 Status Badges

Every backend status enum (order, payment, return, shipment, import, export, module, tenant, subscription, affiliate, payout, commission, leave, attendance overtime) maps to one badge tone in the owning feature's `status.ts`:

| Tone | Used for |
|---|---|
| neutral | draft, pending, queued |
| info | processing |
| success | completed, paid, enabled, approved |
| warning | partially paid, past due, disabled, completed with errors |
| danger | failed, cancelled, suspended, rejected |
| muted | archived, locked, expired, waived |

### 23.3 Theming

**Admin apps and the affiliate portal:**

- Light and dark themes through `next-themes` (already wired), following the system with a user override.
- The tenant admin shows the store's logo and name in the sidebar, but never applies storefront colours.

**Storefront.** Theme settings come from `GET /api/storefront/config` `storefront`:

- `theme`: one of `default`, `minimal`, `bold` or `classic`;
- five colours: `color_primary`, `color_secondary`, `color_accent`, `color_background`, `color_text`;
- `font_heading` and `font_body`, each one of `inter`, `roboto`, `open-sans`, `lato`, `montserrat`, `poppins`, `playfair-display` or `merriweather`.

| Concern | Rule |
|---|---|
| Themes | Each identifier is a theme module `apps/storefront/src/themes/{id}/`: header, footer, product card and gallery layout, plus default tokens. The identifier list is generated from the backend's `config/storefront.php` (§13.3). A build check fails when the backend lists a theme the storefront does not implement. An unknown value falls back to `default`. |
| Colours | Injected as CSS variables on `<html>` in the root layout, from the cached config. Values are re-validated against `^#[0-9a-fA-F]{6}$`, and invalid values are dropped. |
| Contrast | Foreground tokens (`--primary-foreground`, …) are computed at render time for WCAG AA against each chosen colour, so a tenant cannot make unreadable buttons. |
| Fonts | Each identifier maps to a self-hosted font through `next/font/local`, with a variable per font and only the two chosen fonts preloaded. There are no runtime font URLs. |
| Dark mode | Not offered on the storefront. The tenant's colours define the look. |
| RTL | `dir="rtl"` when `rtl_enabled`. Components use logical properties, and `components.json` has `rtl: true`. |
| Logo and favicon | `business.logo_url` and `business.favicon_url` from the config |

Adding a theme or a font is a coordinated release: the backend adds the identifier, and the storefront implements it in the same release.

### 23.4 Rich Text

CMS bodies, blog posts, product descriptions and job descriptions are rich text in an HTML subset. The frontend treats them as untrusted:

- **Allowed elements:** `p`, `br`, `h2`–`h4`, `strong`, `em`, `u`, `s`, `a`, `ul`, `ol`, `li`, `blockquote`, `code`, `pre`, `hr`, `img`, `table`, `thead`, `tbody`, `tr`, `th`, `td`.
- **Allowed attributes:** `a[href]` (`http`, `https`, `mailto`, `tel`); `img[src|alt|width|height]` (https on the media host only); `th[scope]`; `td[colspan|rowspan]`.
- Rich text is sanitised on the server with `sanitize-html` before rendering. Links to other hosts get `rel="noopener noreferrer nofollow"` and `target="_blank"`.
- The Tiptap editor in both admin apps is configured with exactly these extensions.
- Plain-text fields, including `textarea` custom fields, render as text and never as HTML.

### 23.5 CMS Rendering

`packages/cms-render` renders the section types common to both scopes: `rich_text`, `hero`, `image_with_text`, `features_grid`, `call_to_action`, `testimonials`, `faq`, `contact_form`. Scope-specific sections are rendered by the app that owns the scope:

| Section | Renderer |
|---|---|
| `pricing_table`, `plan_comparison`, `legal_document` | `platform-web` |
| `product_carousel`, `category_grid`, `brand_strip` | `storefront` |

- Each app passes its registry to `<CmsSections sections registry />`.
- A type missing from the registry renders nothing and logs a warning with the page slug. So does a dynamic section whose data is missing.
- `testimonials` and `faq` render in the tenant scope only when `content_marketing` is visible.
- **CMS editor** (`admin-kit/cms`, used by both admin apps):
  - page list and page form: title, slug, status, SEO fields, `robots`, `canonical_url`, image;
  - sections with drag reordering (`PUT …/pages/{page}/sections`) and a form per section type;
  - menus with items (`PUT …/menus/{menu}/items`);
  - publish, unpublish and set-homepage actions.
- **Preview** renders the unsaved form data with the target app's renderer inside an isolated iframe of that app. Draft read access is BG-16.

---

# Part IV: The Applications

## 24. platform-web

### 24.1 Routes

| Route | Content | API |
|---|---|---|
| `/` | Landlord CMS homepage. Captures `?ref=` (§24.4). | `GET /api/cms/home` |
| `/pages/[slug]` | Landlord CMS page | `GET /api/cms/pages/{slug}` |
| `/pricing` | Plans and prices, with a monthly and yearly toggle and a currency selector | `GET /api/plans?currency=`, `GET /api/plans/{slug}` |
| `/blog`, `/blog/[slug]` | Platform blog | `GET /api/cms/blog-posts`, `/blog-posts/{slug}`, `/blog-categories`, `/tags` |
| `/faq` | FAQs | `GET /api/cms/faqs`, `/faq-categories` |
| `/legal/[type]` | Legal documents | `GET /api/legal-documents/{type}` |
| `/contact` | Contact form | `POST /api/contact` |
| `/signup`, `/register/verify`, `/signup/payment`, `/signup/status` | Tenant signup (§24.3) | `/api/register*` |
| `/oauth/[provider]/callback` | Social-login relay (§24.5) | Storefront config check |
| `/robots.txt` | `robots.ts` | Platform config |
| `/sitemap.xml` | Edge-routed to Laravel | `landlord.sitemap` |

The site-wide layout (header, footer, menus) uses `GET /api/platform/config` and `GET /api/cms/menus/{key}`, cached as in §14.4. Announcement banners use `GET /api/cms/banners`.

### 24.2 Rendering

- Every public page is a Server Component with cached data.
- Signup steps and the contact form are client islands.
- The site holds no session.
- Analytics scripts may load here, which is why no authenticated surface shares this origin (§6.2).

### 24.3 Tenant Signup

| Step | Route | Behaviour |
|---|---|---|
| 1. Plan | `/pricing`, then `/signup?price={plan_price_id}` | The chosen price is carried in the URL. |
| 2. Details | `/signup` | Business name, owner name, email, password and confirmation, country (lookup), optional currency, price, optional platform coupon (`POST /api/platform-coupons/validate`), and one checkbox per document from `GET /api/legal-documents/current` that is required at registration. Submits `POST /api/register` with `ref` and `referral_token` from the affiliate cookie (§24.4). A 503 `registration_unavailable` shows "Signups are paused". A 422 `legal_version_outdated` reloads the documents and asks for acceptance again. |
| 3. Verify | `/register/verify?registration={public_id}[&code]` | The backend's link path (D-141). A six-digit code input submits `POST /api/register/verify`. A link that carries `code` verifies automatically. Resend (`POST /api/register/resend`) is allowed once per 60 seconds with a countdown. `verification_code_invalid` and `verification_expired` show inline. |
| 4. Payment | `/signup/payment?registration={public_id}` | When `next_action = payment`, the browser goes to `checkout_url`. `POST /api/register/{registration}/checkout` gets a fresh URL after a failed or abandoned payment. |
| 5. Provisioning | `/signup/status?registration={public_id}` | Polls `GET /api/register/{registration}/status` every 15 seconds. After 10 minutes it stops and says the owner will receive an email. |
| 6. Done | same page | When `active`, a button opens `https://{slug}.admin.ROOT/login?email={email}`. The slug is the first label of the returned `domain`. |

**As built (slice 2).** Implemented in `apps/platform-web/src/features/signup` and verified end to end against the backend (Basic plan with a trial: sign-up, verification by link, provisioning, sign-in to the new store's admin).

- **Admin URL.** The done link is built from `TENANT_ADMIN_URL`, a template with `{slug}` (§37.2). Locally it is `http://{slug}.admin.localhost:3001`.
- **Email for the done link.** The owner's email is kept in `sessionStorage` for the tab, never the password. A different browser or tab gets the link without `?email=`.
- **Referral.** Until §24.4 capture ships, `ref` is read from the `?ref=` query and carried from `/pricing` to `/signup`. `referral_token` is not sent yet.
- **Payment methods.** Step 4 offers Paystack, Flutterwave and Stripe as fixed options, because no public route lists the enabled gateways (BG-19). An unavailable gateway shows the backend's error.
- **Rendering.** Prices are formatted with one fixed locale (`SITE_LOCALE`), so server and browser output hydrate identically.
- **Homepage.** `/` is a static hero until the CMS homepage ships.


### 24.4 Affiliate Referral Capture

1. **Capture.** A landing URL carrying `?ref={code}` calls `POST /bff/api/affiliate-clicks` with `{code, visitor_id?, landing_path, referrer, utm_*}`. The BFF stores the returned `referral_token` and `visitor_id` in the `__Host-ref` cookie (HttpOnly, 30 days or the returned `expires_at`) and removes `ref` from the address bar with `history.replaceState`.
2. **Registration.** `POST /api/register` sends the `ref` code and the `referral_token` from the cookie. The backend applies attribution precedence: coupon, then `ref`, then token.
3. **Programme page.** `/affiliates` (a CMS page or a static page) describes the programme from `GET /api/affiliate-program` and links to `https://affiliates.ROOT/apply`.

### 24.5 OAuth Relay

The provider consoles register `https://ROOT/oauth/google/callback` and `https://ROOT/oauth/facebook/callback` as the only redirect URIs. The route handler:

1. **Validates the parameters.** It requires `code` and `state`. A `state` that is not exactly `{base64url}.{random}` gets a 400 page.
2. **Decodes the target.** It decodes the prefix into a URL, then requires the `https` scheme (or `http` for local `.localhost` roots), no credentials, no port except the configured local edge port, and the path `/`.
3. **Verifies that the host is a live tenant store.** It calls `upstream()` with `Host: {decoded host}` and `GET /api/storefront/config`, which is a cached lookup. A 200 means Laravel resolved a tenant from that host. Anything else gets an error page ("This sign-in link is not valid"). This stops the relay being an open redirect or a code-exfiltration hop.
4. **Redirects.** It responds 303 to `{origin}/auth/social/{provider}/callback?code=…&state=…`. The provider's `error` and `error_description` are forwarded instead of `code` when the user cancelled.

Only a short-lived authorisation code passes through, bound to the relay's redirect URI and exchangeable only with the platform's client secret. The relay stores nothing and logs no parameter values.

---

## 25. platform-admin

### 25.1 Features and Navigation

| Group | Entry and route | API (landlord `/api/admin/…`) | Notes |
|---|---|---|---|
| Home | Dashboard `/dashboard` | `dashboard`, `dashboard/{section}` | 8 sections (§22.1) |
| Tenants | Tenants `/tenants` | `tenants`, `tenants/metrics`, `tenants/{tenant}`; actions `suspend`, `reactivate`, `close`, `restore`, `export` | Detail tabs: overview, domains, subscription, modules (`tenants/{tenant}/modules`), feature overrides (`…/features`), limit overrides (`…/limit-overrides`), settings (`…/settings`), support conversations |
| Tenants | Registrations `/tenant-registrations` | `tenant-registrations` | Read-only list |
| Tenants | Database servers `/database-servers` | `database-servers` (index, store, update) | |
| Commerce | Plans `/plans` | `plans` CRUD, `deactivate`, `features`, `limits`, `prices` | Plan editor with features, limits and prices tabs |
| Commerce | Subscriptions `/subscriptions` | `subscriptions`, `metrics`, `{subscription}`, `extend-trial` | |
| Commerce | Transactions `/payment-transactions` | `payment-transactions`, `metrics`, `{transaction}`, `refund` (idempotent) | |
| Commerce | Coupons `/platform-coupons` | `platform-coupons` CRUD, `deactivate`, `redemptions`, `metrics` | |
| Commerce | Commissions `/platform-commissions` | `platform-commissions`, `{commission}/waive` | Filters: tenant, status, currency (D-138) |
| Commerce | Payment gateways `/payment-gateways` | `payment-gateways`, `mode`, `{provider}/{mode}` update, `test`, `enable`, `disable`, `set-default` | Credentials form. Secrets are write-only, never displayed after save. |
| Affiliates | Affiliates `/affiliates` | `affiliates`, `metrics`, `{affiliate}`; `approve`, `reject`, `suspend`, `reinstate`, `close`, `commission-rate`, `referral-code` | |
| Affiliates | Referrals `/affiliate-referrals` | `affiliate-referrals`; `clear-flags`, `reject` | |
| Affiliates | Commissions `/affiliate-commissions` | list, `metrics`, show; `approve`, `reject`, `reverse` | |
| Affiliates | Payouts `/affiliate-payouts` | list, `metrics`, show; `generate`, `mark-paid`, `mark-failed`, `cancel` | |
| Operations | Module notices `/module-notices` | `module-notices` CRUD | |
| Operations | Helpdesk `/platform-support` | `platform-support/conversations`, `{conversation}` (`PATCH`), `messages`, `notes` | Realtime (§21.2) |
| Operations | Exports `/exports` | `exports` (§19.3) | |
| Content | CMS `/cms` | `cms/*` (pages with sections and images, menus, banners, blog, FAQs, testimonials, tags, contact submissions) | `admin-kit/cms` |
| Content | Legal documents `/legal-documents` | `legal-documents`, `publish`, `{document}/acceptances` | |
| Administration | Platform users `/platform-users` | `platform-users` (index, store, update, deactivate, role assign and revoke) | Invitation sends the backend's password-set link. |
| Administration | Notifications `/notification-templates` | `notification-templates` (update, reset), `notifications/matrix` | |
| Administration | Settings `/platform-settings` | `platform-settings`, `{group}` | Grouped forms, one save per group |

Platform-user roles are assigned from lookups. The landlord API has no role CRUD, so none is shown.

#### 25.1.1 Build Status

| Screen | Status | Verified by |
|---|---|---|
| Dashboard, Settings, Registrations | Built | Browser QA; `console.mobile.spec.ts` |
| Payment gateways | Built | `payment-gateways.spec.ts`, `console.mobile.spec.ts`, `payment-gateways/api.test.ts` |
| Plans (list, new, editor with details, prices, features, limits) | Built | `plans.spec.ts`, `console.mobile.spec.ts` |
| Every other §25.1 entry | Not built yet | — |

**Payment gateways, as built:**

- **Layout.** Test and Live tabs (`?mode=`) open on the current billing mode. Each card shows its status (Not connected, Needs a test, Ready to enable, Enabled), the Default badge, the masked public key, whether each secret is stored, currencies, countries, and when keys were last verified and a webhook was last received.
- **Webhook URL.** Every card shows `webhook_url` with a copy button, to paste into the provider's dashboard.
- **Webhook secret per provider.** Paystack signs webhooks with the secret key, so its card says "Not needed" and its form has no webhook-secret field. Flutterwave (secret hash) and Stripe (`whsec_…`) show a warning when the secret is missing, because their webhooks would be rejected.
- **Enable rule.** Enable is offered only within 24 hours of a successful test, mirroring the backend's `gateway_not_verified` rule.
- **Credentials form.** A side sheet (a bottom sheet on phones). Secrets are write-only, and an empty field keeps the stored value. A save makes the backend check the keys with the provider; `gateway_credentials_invalid` and `payment_mode_mismatch` appear under the secret-key field.
- **Billing mode.** Switching needs a reason (§15.9 safeguard 5). The page warns when no gateway is enabled in the current mode.
- **Gating.** Every action is gated by its route name (`useCan`).
- **Contract types.** `has_secret_key` and `has_webhook_secret` are booleans in the contract. Scramble infers resource arrays from the method body, so the backend casts them with `(bool)`; a return docblock alone is not enough.

**Plans, as built:**

- **Routes.** `/plans` (list), `/plans/new`, and `/plans/[plan]` with tabs (`?tab=details|prices|features|limits`).
- **Create.** New plans are created inactive. The editor opens on Prices, and **Activate plan** needs an active price. `plan_limits_incomplete` is shown as a form error. Deactivating asks for confirmation and states that existing subscribers keep their plan.
- **Prices.** Amounts are immutable (§11.6): the tab offers **Add price** and a trial edit only. The add dialog warns when the new price replaces the active one for the same currency and interval. Retire and reactivate ask for confirmation. An empty trial means the platform default, and the table shows the resolved trial.
- **Features.** A switch per entry of the admin `features` lookup, grouped by module, capability and integration, with search. An included feature whose `requires` are missing shows a warning.
- **Limits.** Every registered limit, with **Unlimited** where `unlimited_allowed`, saved as one request per changed key.
- **Contract types.** `PlanResource.limits` (a key→value map), `resolved_trial_days` and `limit_value` (integers) are typed through `@var` annotations and an explicit cast in the backend. An earlier "Scramble ignores @var" note was wrong: the pull was copying a stale document (see §13.3).
- **Test data.** Writes in `plans.spec.ts` go to one dedicated, inactive, hidden plan (slug `e2e-test-plan`, order 9999), created once and reused, because the API has no plan delete. Feature and limit changes are reverted in the same test.

**Shared conventions from these slices:**

- **Links styled as buttons** use `ButtonLink` (`@workspace/ui/components/button-link`), so they are announced as links. A Base UI `Button` rendering an anchor announces itself as a button. `PaginationLink` is a plain anchor for the same reason.
- **After a mutation**, the response is written into the query cache and the refetch runs in the background (not awaited), so dialogs close at once.
- **Short static lists** use `StaticCombobox` or `MultiCombobox` from `@workspace/admin-kit/lookup`. API-backed records keep `EntityCombobox`.

**platform-admin tests.** `pnpm --filter platform-admin test` runs vitest. `E2E_PASSWORD=… pnpm --filter platform-admin test:e2e` runs Playwright on one worker against the dev server. It signs in once and saves the session in the git-ignored `e2e/.auth/`. Desktop specs run at 1440 px, and every console page is checked at phone width for page errors and horizontal overflow.

### 25.2 Session and Access

- **Snapshot:** `GET /api/admin/auth/me` (`user`, `roles`, `permissions`, `display`).
- **Gating:** navigation and actions are gated by permission. There are no modules on the landlord.
- **Session:** the 12-hour token is refreshed at 75% of its lifetime through `POST /api/admin/auth/refresh` (BG-10, D-143). This uses the same `SessionKeeper` as staff (`@workspace/admin-kit/auth`), with a cross-tab lock and a logout broadcast.
- **Auth pages:** login, forgot password and reset password share `@workspace/admin-kit/auth` with tenant-admin. The dashboard shares `@workspace/admin-kit/dashboard` (`SectionDashboard`).
- **Preferences:** `PATCH /api/admin/auth/preferences` sets date and time format.

---

## 26. affiliate-portal

| Route | API | Notes |
|---|---|---|
| `/apply` | `POST /api/affiliate/auth/apply` | Includes legal acceptance. The account starts pending. |
| `/login`, `/verify-email`, `/forgot-password`, `/reset-password` | `/api/affiliate/auth/*` | `/verify-email` and `/reset-password` match the backend links. |
| `/` dashboard | `GET /api/affiliate/dashboard` | KPI grid (§22.1 shape) |
| `/referrals` | `GET /api/affiliate/referrals` | |
| `/commissions` | `GET /api/affiliate/commissions` | Status badges |
| `/payouts`, `/payouts/[payout]` | `GET /api/affiliate/payouts`, `/{payout}` | |
| `/settings/payout-details` | `PUT /api/affiliate/payout-details` | Sensitive: shows masked values from the profile, with an explicit replace flow |
| `/settings/profile`, `/settings/security` | `GET`/`PATCH /api/affiliate/profile`, `PATCH /api/affiliate/auth/password` | |
| `/notifications` | `GET /api/affiliate/notifications`, `POST …/{id}/read` | |
| Legal banner | `GET /api/affiliate/legal-documents/pending`, `POST /api/affiliate/legal-acceptances` | §11.6 |

The referral link shown on the dashboard is `https://ROOT/?ref={referral_code}`, the backend's format. It has a copy button.

---

## 27. tenant-admin

### 27.1 Shell

The staff shell (§17.1) uses the snapshot from tenant `GET /api/admin/auth/me`. The seller portal (§28) is a separate shell and session in the same app.

### 27.2 Navigation Groups and Features

Paths mirror the API (§15.1), and every entry's module and permission come from the manifest. "Module" below is the feature key, and core features have none.

| Group | Entries (base path) | API family | Module |
|---|---|---|---|
| Home | Dashboard `/dashboard` (with the onboarding card) | `dashboard`, `onboarding` | core |
| Sales | Orders `/orders` | `orders` (17 routes incl. `bulk`, `metrics`, status actions, notes, invoice) | core |
| Sales | Returns `/returns`, Return reasons (dialog) | `returns`, `return-reasons` | core |
| Sales | Shipments `/shipments`, Delivery assignments `/delivery-assignments`, Drivers `/drivers` | `shipments`, `delivery-assignments`, `drivers` | core |
| Sales | Order payments `/order-payments` | `order-payments` (manual payments and refunds are idempotent) | core |
| Sales | Sales quotations `/sales-quotations`, requests `/sales-quotation-requests` | `sales-quotations`, `sales-quotation-requests` | `sales_quotations` |
| Sales | Product subscriptions `/product-subscriptions` | `product-subscriptions` | `product_subscriptions` |
| Sales | Installment plans `/installment-plans` | `installment-plans` | `installments` |
| Sales | POS `/pos` (terminal), `/pos/registers`, `/pos/sessions`, `/pos/settings` | `pos/*` | `pos` |
| Catalogue | Products `/products` (46 routes: variants, bundles, digital files, media, prices, specifications, `bulk`) | `products` | core |
| Catalogue | Categories, Brands, Tags, Options `/product-options`, Units | `categories` (`bulk`), `brands`, `tags`, `product-options`, `units` | core |
| Catalogue | Reviews `/reviews` (`bulk`), Questions `/product-questions`, Answers | `reviews`, `product-questions`, `product-answers` | core |
| Catalogue | Print barcodes `/print-barcode` | `print-barcode` | core |
| Inventory | Warehouses, Stock `/inventory`, Transfers, Adjustments | `warehouses`, `inventory`, `stock-transfers`, `stock-adjustments` | core |
| Customers | Customers `/customers` (`bulk`, `activate`), Groups | `customers`, `customer-groups` | core |
| Customers | Support `/support` | `support/*` | `support` |
| Customers | Reward points `/reward-points` | `reward-points` | `reward_points` |
| Customers | Gift cards `/gift-cards` | `gift-cards` (issue is idempotent) | `gift_cards` |
| Marketing | Promotions, Coupons (`bulk`), Flash sales | `promotions`, `coupons`, `flash-sales` | core |
| Marketing | Content `/cms` | `cms/*` (53 routes; blog, FAQs, testimonials need `content_marketing`) | core and `content_marketing` |
| Marketplace | Sellers, Seller products, Seller groups | `sellers`, `seller-products`, `seller-groups` | `marketplace` |
| Purchasing | Suppliers, Purchase orders, Supplier quotations, Quotation requests, Purchase returns, Return reasons, Supplier payments | `suppliers`, `purchase-orders`, `supplier-quotations`, `quotation-requests`, `purchase-returns`, `purchase-return-reasons`, `supplier-payments` | `purchasing` |
| Finance | Accounting `/accounting` (accounts, categories, fiscal years and periods, journal entries, posting requests, reports) | `accounting/*` | `accounting` |
| Finance | Expenses, Income, categories | `expenses`, `expense-categories`, `income`, `income-categories` | `expenses` |
| Finance | Billers `/billers` | `billers` | core |
| Finance | Sales agents, commissions | `sales-agents`, `sales-agent-commissions` | `sales_agents` |
| People | HR `/hr` (§27.5) | `hr/*` | `hr`, `hr_payroll`, `hr_recruitment` |
| People | Staff users `/users`, Roles `/roles` | `users`, `roles`, `permissions` | core |
| Operations | Projects `/projects`, categories | `projects`, `project-categories` | `project_management` |
| Operations | Manufacturing `/bill-of-materials`, `/work-orders` | `bill-of-materials`, `work-orders` | `manufacturing` |
| Operations | Restaurant `/restaurant` (floors, tables, modifier groups, reservations, table orders), Kitchen display `/kitchen` | `restaurant/*` | `restaurant` |
| Operations | Bookings `/bookings`, Booking staff | `bookings`, `booking-staff` | `booking` |
| Operations | Repair jobs `/repair-jobs` | `repair-jobs` | `repair` |
| Operations | Approvals `/approvals`, Workflows `/approval-workflows` | `approvals`, `approval-workflows` | `approval_workflows` |
| Insights | Reports `/reports` | `reports/*` | core and `advanced_reporting` |
| Insights | AI assistant `/ai-assistant` | `ai-assistant` | `ai_assistant` |
| Insights | Imports `/imports`, Exports `/exports` | `imports`, `exports` (§19) | core |
| Integrations | WooCommerce `/woocommerce`, Social commerce `/social-commerce` | `woocommerce`, `social-commerce` | `woocommerce`, `social_commerce` |
| Settings | Store `/settings` | `settings` | core |
| Settings | Storefront `/settings/storefront` | `storefront-settings` | core |
| Settings | Domains `/settings/domains` | `domains` (verify, make-primary) | core |
| Settings | Payments `/settings/payments` | `payment-settings` (test and live credentials) | core |
| Settings | Currencies `/settings/currencies` | `currencies` (incl. refresh rates) | `multi_currency` |
| Settings | Tax `/settings/tax-rates` | `tax-rates` | core |
| Settings | Shipping `/settings/shipping` | `shipping-zones`, `shipping-methods` | core |
| Settings | Messaging `/settings/messaging` | `sms-gateway-settings`, `whatsapp-settings` | core and `whatsapp` |
| Settings | Notifications `/settings/notifications` | `notification-templates`, `notifications/matrix`; personal `notification-preferences` | core |
| Settings | Documents `/settings/documents` | `invoice-templates`, `barcode-settings`, `receipt-printers` | core |
| Settings | Custom fields `/settings/custom-fields` | `custom-fields` | core |
| Settings | Modules `/settings/modules` | `modules` | core |
| Settings | Billing `/settings/billing` (plans, subscription, plan-change preview, swap, cancel, transactions, usage, commissions) | `billing/*` | core |
| Settings | Platform support `/platform-support` | `platform-support` | core |

**Fixed backend link targets** (§3.8):

| Route | Behaviour |
|---|---|
| `/billing/callback` | Refetches the subscription and polls it for 60 seconds, then redirects to `/settings/billing`. |
| `/exports/[export]` | Export detail |
| `/imports/[import]` | Import detail |
| `/reset-password` | Staff password reset |
| `/` | Redirects to `/dashboard` |

### 27.3 Owner Dashboard and Onboarding

`/dashboard` shows the onboarding card above the sections while the owner's checklist is incomplete and not dismissed.

- The card reads `GET /api/admin/onboarding`, which returns `steps: [{key, complete, optional}]`, `completed`, `total` and `dismissed`.
- Step keys: `store_details`, `payment_gateway`, `shipping`, `tax`, `first_product`, `policies`, and the optional `custom_domain`.
- Each step links to its screen: store details to `/settings`, gateway to `/settings/payments`, shipping, tax, the new-product page, CMS policy pages and domains.
- **Dismiss** sets the `onboarding_dismissed` tenant setting through `PATCH /api/admin/settings`.

### 27.4 Billing

| Screen | API |
|---|---|
| Overview | `GET /api/admin/billing/subscription`, `GET /api/admin/billing/usage` |
| Plans | `GET /api/admin/billing/plans`; savings shown only when returned; `POST /api/admin/billing/coupon-preview` |
| Change plan | `GET /api/admin/billing/subscription/plan-change-preview`, then `POST …/swap-plan` (idempotent). Locked modules and exceeded limits need an explicit confirmation (`confirm_impact: true`). A 422 `plan_change_requires_confirmation` reopens the preview. |
| Subscribe or pay | `POST /api/admin/billing/subscription` (idempotent), then `checkout_url`, which returns to `/billing/callback` |
| Cancel | `POST /api/admin/billing/subscription/cancel`, stating the end date and that data is kept |
| Transactions | `GET /api/admin/billing/transactions` |
| Commissions | `GET /api/admin/billing/commissions`, with `meta.outstanding` per currency (D-138) |

The frontend never computes a price, saving, proration or commission. It displays the API's values.

### 27.5 Human Resources

| Area | Routes (under `/hr`) | Module |
|---|---|---|
| Settings, departments and department settings | `hr/settings` (incl. overtime policy), `hr/departments`, `…/settings` | `hr` |
| Employees, documents, expiring documents, document types | `hr/employees`, `…/documents`, `hr/documents/expiring`, `hr/document-types` | `hr` |
| Attendance | Self-service clock-in and clock-out (`hr/attendance/clock-in`, `clock-out`, `employee_id` with `hr.attendance.record`), `hr/attendance/summary`, `hr/employees/{id}/attendance` | `hr` |
| Shifts and roster | `hr/shifts` CRUD, `hr/roster` (week grid), `hr/roster/assign` (employees × date range × weekdays, `replace`), `hr/roster/unassign`; self-service `hr/my-shifts` | `hr` |
| Overtime | `hr/overtime` review queue; `hr/attendance/{id}/overtime/approve` (minutes ≤ recorded), `…/reject` | `hr` |
| Leave | `hr/leave-types`, `hr/employees/{id}/leave-balances`, `hr/leave-requests` (self-service create and cancel; approve and reject) | `hr` |
| Appraisals | `hr/appraisal-templates` and criteria, `hr/employees/{id}/appraisals`, `…/scores`, `submit`, self-service `acknowledge` | `hr` |
| Payroll | `hr/employees/{id}/salary` (`base_salary`, `hourly_rate`), `hr/payroll-runs` (create, generate items, finalize, mark paid), `hr/payroll-items` (lines, recalculate, mark paid), payslips and history | `hr_payroll` |
| Recruitment | `hr/job-postings` (publish, close), `…/applications`, application status and notes, résumé download, convert to employee | `hr_recruitment` |

Self-service routes (clock-in and out, own leave, own shifts, appraisal acknowledgement) are visible to every staff user with a linked employee.

- **"My work" area.** They appear under a "My work" area in the user menu, not in the People group.
- **Unlinked staff.** A 422 `no_linked_employee` renders "Your account is not linked to an employee record."
- **Roster grid.** Employees × days for the selected week (the `from` and `to` parameters, at most 93 days), with shift colour chips. Bulk assignment uses a dialog.

### 27.6 Point of Sale

**Placement.** `/pos` is a full-screen route group: no sidebar, touch targets of at least 44 px, and keyboard-wedge barcode input (a fast input burst ending with Enter). It requires `pos` to be `enabled`. Registers, sessions and settings are normal admin pages.

| Step | API |
|---|---|
| Choose a register | `GET /api/admin/pos/registers` |
| Current session, or open one | `GET /api/admin/pos/registers/{register}/current-session`; `POST /api/admin/pos/sessions` |
| Add lines | `GET /api/admin/pos/products/lookup?barcode=` or `?q=` |
| Quote | `POST /api/admin/pos/quote` after each line change, debounced by 250 ms |
| Payment | Cash (tendered amount, change shown), card terminal (`POST /api/admin/pos/terminal-charges`, then poll `GET …/terminal-charges/{reference}` every 2 s), or split, using the methods from `GET /api/admin/lookups/pos-payment-methods` |
| Complete | `POST /api/admin/pos/sales` with a client `idempotency_key` in the body |
| Receipt | `GET /api/admin/pos/sales/{order}/receipt`, rendered in a hidden iframe and printed with `window.print()` |
| Void | `POST /api/admin/pos/sales/{order}/void` |
| Close session | `POST /api/admin/pos/sessions/{session}/close` with counted cash. The summary shows the variance. |

**State.** The current sale (lines, customer, payments) lives in a POS-scoped Zustand store persisted to IndexedDB, so a reload does not lose it.

**Offline tolerance** (API §51.6):

| Capability | v1 |
|---|---|
| Connection lost with the page loaded | Sales queue in IndexedDB with their `idempotency_key`. A banner shows the queue size, and sync runs in order on reconnect. |
| Offline pricing | Only products already looked up in the session, at their last quoted unit prices, with no coupons. A full register snapshot is BG-14. |
| Cold start offline | Not supported: it needs a service worker and offline auth (§43). |
| Sync conflict (409 `stock_conflict`) | The sale moves to "Needs attention" with the affected lines. Nothing is dropped. |
| Stale sale | The backend refuses sales older than `POS_OFFLINE_SALE_MAX_AGE_HOURS` (72). The UI shows the refusal and keeps the sale for manual entry. |

Cash change and offline totals are the only money arithmetic in the frontend. They use integer minor units and are display-only. The server recomputes on sync.

**Kitchen display.** `/kitchen` (`restaurant`) is a full-screen board that polls `GET /api/admin/restaurant/kitchen` every 10 seconds, with large status actions. It does not use realtime, because the backend has no kitchen broadcasts.

---

## 28. Seller Portal

The seller portal is served by `tenant-admin` under `/seller`, with the `__Host-seller` cookie and its own shell. It requires `marketplace`.

| Route | API |
|---|---|
| `/seller/login`, `/seller/register`, `/seller/forgot-password`, `/seller/reset-password` | `/api/seller/auth/*` |
| `/seller` dashboard and profile | `GET`/`PATCH /api/seller/profile` |
| `/seller/products` (CRUD, variants, media, digital files, bundle items, specifications) | `/api/seller/products*`, `/api/seller/product-options`, `/api/seller/units` |
| `/seller/orders` | `GET /api/seller/orders` |
| `/seller/ledger`, `/seller/payouts` | `GET /api/seller/ledger`, `/api/seller/payouts` |
| Question answers | `POST /api/seller/product-questions/{question}/answers` |
| Security | `PATCH /api/seller/auth/password` |

- A `pending` seller who signs in gets the backend's refusal, shown as "Your application is being reviewed."
- The storefront's `/seller` page is a "Sell with us" link to `https://{slug}.admin.ROOT/seller/register`.

---

## 29. storefront

### 29.1 Rendering Model

| Content | Rendering | Cache |
|---|---|---|
| Layout chrome (header, footer, menus, announcement bar, theme variables) | Server Component | Config and menu cache |
| Home, CMS pages, blog | Server Components | CMS cache |
| Listings (all, category, brand, tag), search | Server Components; filter controls are a client island writing to the URL | Catalogue cache, keyed by the normalised filters and currency |
| Product page | Server Component for content; client islands for variant selection, add to cart, wishlist, stock alert, Q&A form | Product cache |
| Cart count, account menu, wishlist state | Client islands through the BFF | TanStack Query, not cached on the server |
| Cart, checkout, payment return, account, guest order pages | Client-heavy pages with a server shell | Never cached |

`cacheComponents: true` is enabled. The static shell and cached data render immediately, and personal islands stream behind Suspense. Personal data is never read inside a cached function.

### 29.2 Routes

| Route | Content | API |
|---|---|---|
| `/` | Homepage sections | `GET /api/cms/home` |
| `/products` | All products | `GET /api/products` (filters, `sort`: `price_asc`, `price_desc`, `newest`, `best_selling`, `top_rated`, `trending`) |
| `/products/[slug]` | Product detail | `GET /api/products/{slug}`, `/related`, `/questions`, `/reviews`; `available-slots` (booking), `subscription-plans` (product subscriptions) |
| `/categories/[slug]`, `/brands/[slug]` | Listings | `GET /api/categories/{slug}` and `/products`, and the same for brands |
| `/tags/[slug]` | Tag listing | `GET /api/products?tag={slug}` |
| `/search` | Search results | `GET /api/products?search=` |
| `/pages/[slug]` | CMS page (policies included) | `GET /api/cms/pages/{slug}` |
| `/blog`, `/blog/[slug]`, `/faq` | Content (`content_marketing`) | `/api/cms/blog-*`, `/api/cms/faq*` |
| `/flash-sales` | Active flash sales | `GET /api/flash-sales` |
| `/contact` | Contact form | `POST /api/contact` |
| `/cart` | Cart | `GET /api/cart` and cart routes |
| `/checkout` | Steps `?step=details\|delivery\|payment` | Cart quote, `GET /api/shipping/methods`, `GET /api/payment-methods`, `POST /api/orders` (idempotent) |
| `/orders/[order]` | Confirmation and tracking (guest and customer) | `GET /api/orders/{order}`, `/tracking`, `/invoice` |
| `/orders/[order]/payment-return` | Gateway return (backend callback) | `POST /api/payments/verify`, `GET /api/orders/{order}` |
| `/account/*` | Customer area (§29.8) | `tenant.customer` routes |
| `/auth/social/[provider]/callback` | Social sign-in and link completion (§9.5) | Social callbacks |
| `/verify-email`, `/reset-password`, `/forgot-password` | Customer auth (backend link paths) | `/api/auth/*` |
| `/gift-cards` | Buy a card (idempotent), check a balance (`gift_cards`) | `POST /api/gift-cards/purchase`, `GET /api/gift-cards/{code}/balance` |
| `/careers`, `/careers/[slug]` | Careers (`hr_recruitment`) | `GET /api/careers/jobs`, `/{slug}`, `POST …/apply` (multipart) |
| `/book/[slug]` | Book a bookable product (`booking`) | `GET /api/products/{product}/available-slots`, `POST /api/bookings` |
| `/seller` | "Sell with us" (`marketplace`) | none |
| `/admin`, `/admin/*` | 308 to the admin host (§15.4) | none |
| `/robots.txt` | `robots.ts` | Config |
| `/sitemap.xml` | Edge-routed to Laravel | `tenant.sitemap` |

These paths are the storefront URL contract shared with `config/cms.php` and `FrontendUrl` (§3.8).

### 29.3 Storefront Configuration

`getStorefrontConfig(apiHost)` loads `GET /api/storefront/config` once per request through the cache. Server components read it directly. Client components receive a small context holding display data only:

- store name, logo, favicon and contact;
- formatting: currency, currencies, currency position, decimals, locale, RTL, timezone, date and time format;
- checkout switches: guest checkout, prices include tax, payment mode, plus the storefront settings `checkout_phone_required` and `checkout_order_notes_enabled`;
- the visible module flags, the social login providers and the announcement bar.

**Module visibility.** A route or widget for a module flagged false returns `notFound()`, and its links are omitted. The flags cover every module with a storefront surface (§3.6).

**Test mode.** When `checkout.payment_mode = test`, a slim banner reads "This store is in test mode. No real payments are taken."

### 29.4 Display Currency

With `multi_currency` visible:

- the currency switcher sets a `currency` cookie (not HttpOnly, not sensitive) and calls `PATCH /api/cart/currency` when a cart exists;
- server data functions take the currency as an argument (part of the cache key) and pass `?currency=`;
- the currency is not in the URL, so canonical URLs stay stable;
- estimated prices (`is_estimated`) show an "approx." marker.

### 29.5 Cart

- **Resource.** The cart is the TanStack Query resource `['cart']`, from `GET /api/cart`.
- **Guest token.** The first cart write issues the guest token in `data.guest_token`. The BFF seals it in `__Host-guest` and nulls it in the body (§8.4).
- **Mutations** replace `['cart']` with the response, which is always the full cart.
- **Cart actions:**
  - add, update and remove item;
  - apply and remove coupon: a 422 with the backend `message` renders inline;
  - apply and remove gift card (`gift_cards`);
  - apply reward points (`reward_points`, customers only);
  - change currency (`multi_currency`).
- **Merge on login.** Customer login and registration send the guest token, so the backend merges the guest cart. The client then invalidates `['cart']`.

### 29.6 Checkout

| Step | Behaviour |
|---|---|
| Details | Email, name, phone (required when `checkout_phone_required`), public `order` custom fields, order notes (when `checkout_order_notes_enabled`). Guests continue only when `guest_checkout_enabled`; otherwise login or registration is required and returns here. |
| Delivery | Saved addresses (customers) or an address form with country and state lookups. Shipping methods come from `GET /api/shipping/methods?address_id=` or `?country_id=&state_id=`. Each change re-quotes with `GET /api/cart?address_id=…&shipping_method_id=…`. |
| Payment | Methods from `GET /api/payment-methods?currency=`. The quote summary shows every line, discount, tax (inclusive or exclusive per `prices_include_tax`) and shipping exactly as returned. Installment eligibility, where enabled, uses `GET /api/orders/{order}/installment-eligibility` after placement. |
| Place order | `POST /api/orders` with the details, the latest `quote_hash`, and an `Idempotency-Key` created when the Payment step was entered |
| Pay | If the order has a balance and the method is a gateway: `POST /api/orders/{order}/pay` with `{gateway}` and a new key. The returned `reference` is stored in `sessionStorage` under `pay:{order}`, then `window.location.assign(checkout_url)`. |

**Conflicts:**

| Error | UX |
|---|---|
| 409 `totals_changed`, `price_changed`, `promotion_unavailable` | Show the fresh quote from `details` beside the old totals, highlight the differences, and require confirmation. Confirming places the order with the new `quote_hash` and a new key. |
| 409 `stock_conflict` | List the affected lines with their availability, and offer to update quantities or remove lines. Return to the cart. |
| 409 `payment_in_progress` | Redirect to the returned `checkout_url` for the pending attempt. |
| 409 `order_expired` | Explain that reserved items were released, and send the customer to the cart. |
| 422 | Field errors on the relevant step |

### 29.7 Payment Return

The gateway returns the customer to `/orders/{order}/payment-return`. Paystack appends `reference`, Flutterwave `tx_ref`, and Stripe nothing.

1. The page takes the reference from the query (`reference` or `tx_ref`), or from `sessionStorage` `pay:{order}`. If found, it calls `POST /api/payments/verify {reference}`, whose response `data` includes the payment `status` and the order's `status` and `payment_status`.
2. **Success** (`successful`, or the order is paid): show the confirmation, clear the checkout keys, and link to `/orders/{order}`.
3. **Pending, or no reference:** poll `GET /api/orders/{order}` every 5 seconds for up to 2 minutes, then say that confirmation will arrive by email. Webhooks and delayed verification complete payment server-side.
4. **Failure:** show the reason and **Try again**, which starts a new payment attempt with a new key.

Guest access works because the guest cookie carries the token that placed the order.

### 29.8 Customer Account

| Route | API | Module |
|---|---|---|
| `/account/login`, `/account/register` | `/bff/auth/*`, social login (§9.5) | core |
| `/account` | `GET /api/account` | core |
| `/account/profile` | `PATCH /api/account` | core |
| `/account/security` | `PATCH /api/auth/password`, `POST /api/auth/password/set`, social accounts (§9.5) | core |
| `/account/addresses` | `/api/account/addresses` (CRUD, default) | core |
| `/account/orders`, `/account/orders/[order]` | `GET /api/orders`, `/{order}`, `POST /cancel`, `/invoice`, `/returns` (`POST` with photos) | core |
| `/account/returns`, `/account/returns/[return]` | `GET /api/returns`, `/{return}` | core |
| `/account/wishlist` | `GET`/`POST /api/wishlist`, `DELETE /{product}`, `POST /{product}/move-to-cart` | core |
| `/account/downloads` | `GET /api/account/downloads`, `GET /api/downloads/{grant}` | core |
| `/account/notifications` | Inbox and preferences (§21.1) | core |
| `/account/support` | `/api/support/conversations` (realtime, §21.2) | `support` |
| `/account/quotations`, `/account/quotations/[id]` | `/api/quotation-requests` (accept, reject) | `sales_quotations` |
| `/account/subscriptions`, `/account/subscriptions/[id]` | `/api/account/product-subscriptions` (pause, resume, update, cancel) | `product_subscriptions` |
| `/account/installments` | Order installment plan (`/api/orders/{order}/installment-plan`), `POST /api/installment-payments/{payment}/pay` | `installments` |
| `/account/rewards` | `/api/account/reward-points/balance`, `/history` | `reward_points` |
| `/account/bookings` | `GET /api/account/bookings`, `PATCH …/{booking}/cancel` | `booking` |
| `/account/repairs`, `/account/repairs/[job]` | `/api/account/repair-jobs`, `PATCH …/approve` | `repair` |
| `/account/privacy` | `POST /api/account/export`, `DELETE /api/account` (current password) | core |
| `/account/export` | Signed export download landing (§19.3) | core |

Guest orders are readable only with the guest token that placed them. A guest who clears cookies loses browser access, and the confirmation email is their record. The guest token never appears in a URL.

### 29.9 SEO

- **Metadata.** `generateMetadata` builds title, description, canonical, Open Graph and Twitter tags from cached data.
  - The title is the page's `meta_title`, or else the entity name, passed through `seo_title_template` (`{page}`, `{store_name}`).
  - The description is `meta_description`, or else the plain-text description truncated to 160 characters, or else `seo_default_description`.
  - The Open Graph image is the entity image, or else the store share image.
  - CMS pages honour `canonical_url` and `robots`.
- **Canonical host.** Canonical URLs use `tenant.primary_domain` from the config. A page `GET` on any other verified domain gets a 308 to the primary domain. `/api/*` and `/sitemap.xml` are never redirected.
- **Robots.** `robots.ts` behaves as follows:
  - when `robots_indexing_enabled` is false, it disallows everything;
  - otherwise it disallows `/cart`, `/checkout`, `/account`, `/orders`, `/auth`, `/bff` and `/search`, and gives the sitemap URL;
  - noindex pages carry `<meta name="robots" content="noindex, nofollow">`;
  - listings with more than one filter carry `noindex, follow` with a canonical to the unfiltered listing.
- **Structured data.** JSON-LD is built from typed helpers in `features/seo`, serialised with `<` escaped as `<`:
  - `Organization` and `WebSite` with a `SearchAction` on every page;
  - `Product` with `Offer` (price string, currency, availability) and `AggregateRating` when ratings exist;
  - `BreadcrumbList` and `ItemList` on listings;
  - `Article` for blog posts, `FAQPage` for FAQs, and `LocalBusiness` when an address and hours exist.
- **Product views.** The backend records views on the product show route, which caching bypasses. BG-15 asks for a separate view endpoint, which the page would call once per view from the client. Until then, view-based sorting (`trending`) reflects only uncached traffic.

### 29.10 Third-Party Scripts and Consent

The storefront loads tenant-configured tracking (Google Analytics measurement ID, Google Tag Manager, Meta pixel) only:

- after consent, when `cookie_consent_enabled` is true, with a banner showing `cookie_consent_text`;
- with the `afterInteractive` strategy;
- through `next/script` with the page CSP nonce (§31.3).

Scripts are never loaded on `/checkout`, `/account` or `/auth` routes.

---

# Part V: Cross-Cutting Requirements

## 30. Error and UX State System

### 30.1 Principles

1. Every error the API can return has a defined presentation.
2. The envelope `message` is safe to show. The backend never returns stack traces outside local development.
3. Every error surface shows the request ID in a "Details" disclosure with a copy button, for support.
4. A failure never discards user input.

### 30.2 Presentation by Status and Code

| Status and code | Scope | Presentation | Retry |
|---|---|---|---|
| `network_error` | Any | Inline error with **Retry**, and an offline banner when `navigator.onLine` is false | Automatic for queries |
| 401 `unauthenticated` | Sessions | Session retry (§9.3), then login with `next`. Unsaved form data is kept in `sessionStorage` for 10 minutes and restored. | No |
| 402 `subscription_past_due` | Admin writes, blocked storefront | Read-only banner (§11.5) or unavailable page | No |
| 402 `subscription_payment_required` | Admin | Billing gate (§11.4) | No |
| 403 `forbidden` | Any | Inline "You don't have permission to do this" on actions, or an access page on navigation. Refetch the snapshot. | No |
| 403 `feature_unavailable`, `module_disabled`, `module_locked`, `module_suspended` | Admin; storefront widgets | Module state page or dialog (§11.2). Refetch the snapshot. The storefront hides the widget. | No |
| 403 `limit_reached` | Admin | Limit dialog (§11.3) | No |
| 403 `tenant_suspended` | Any | Tenant state page | No |
| 403 `csrf_rejected` | Any | "Your session changed in another tab. Reload the page." | Reload |
| 404 `not_found` | Any | Not-found page with a link back. The storefront returns HTTP 404. | No |
| 405 `method_not_allowed` | Any | Treated as a defect: generic error, logged | No |
| 409 checkout codes | Storefront, POS | §29.6, §27.6 | Per case |
| 409 `idempotency_in_progress` | Idempotent actions | Silent retry after `Retry-After` (§12.6) | Up to 5 times |
| 409 `idempotency_conflict` | Idempotent actions | Generic retry message, logged as a defect | With a new key |
| 409 domain conflicts (`social_*`, `shift_in_use`, …) | Actions | Inline alert with `message` and the specific handling in the owning section | No |
| 410 `tenant_closed` | Any | Closed page | No |
| 422 `validation_failed` | Forms | Field errors (§18.4) | No |
| 422 other codes | Actions | Inline alert with `message`, with specific handling where defined (modules, plan change, custom fields, imports, social login, registration) | No |
| 429 `too_many_requests` | Any | Toast with the wait time. Forms show a countdown on the submit button. | After `Retry-After` |
| 500 `server_error` | Any | "Something went wrong" with the request ID and **Retry** | Manual |
| 502 `upstream_unavailable`, 503 without a known code | Any | "Service temporarily unavailable" | Queries only |
| 503 `maintenance` | Any | Maintenance page (platform) or module maintenance state, polling every 60 seconds | Poll |
| 503 `tenant_provisioning` | Tenant hosts | Tenant state page | Poll |
| 503 `registration_unavailable` | Signup | "Signups are paused" | No |

### 30.3 Error Boundaries, Loading and Empty States

- Each app has `app/global-error.tsx` and `app/error.tsx`. Admin pages are wrapped in the `admin-kit` `QueryErrorBoundary`, which renders §30.2 for a thrown `ApiError` and resets on navigation.
- Storefront pages map API errors to HTTP status with `notFound()` or the tenant-state pages, so crawlers see correct status codes.
- Route-level `loading.tsx` renders the skeleton of the page type, matching the final layout to avoid layout shift. Mutations show pending state on the triggering control, never as a full-page spinner.
- Empty states say what the user can do next, and never offer an action the user lacks permission for.

---

## 31. Security

### 31.1 Threat Model

| Threat | Mitigation |
|---|---|
| Token theft through XSS or tenant third-party scripts | Tokens only in `HttpOnly` sealed cookies (§8.2). Authenticated surfaces on origins that run no third-party scripts (§6.2). Strict CSP (§31.3). |
| Cross-tenant data access | Host-only tenant resolution, with Laravel re-resolving from `Host` (§7.5). Sessions bound to `apiHost`. `__Host-` cookies. Tenant-keyed caches. |
| Personal data in a shared cache | Cached functions never send session headers and take `apiHost` first (§14.4). Authenticated responses are never cached server-side. |
| CSRF on the BFF | `SameSite=Lax`, `Origin` check and `X-Requested-With` (§8.6) |
| Open redirects | `next` validation (§9.2); relay target validation against a live tenant (§24.5); host redirects built only from the manifest or config |
| OAuth code interception | Single-use state bound to tenant, provider and intent (backend). A relay that only forwards to verified tenant storefronts. A `__Host-oauth` cookie binding the flow to the browser that started it (§9.5). |
| SSRF through the BFF | Allow-listed path prefixes, normalised paths, a fixed `LARAVEL_INTERNAL_URL`, and no user-controlled upstream host except the validated tenant host |
| Stored XSS in tenant content | Server-side rich-text sanitisation with an allow-list (§23.4). JSON-LD `<` escaping. React escaping everywhere else. `dangerouslySetInnerHTML` only in `cms-render`, and only with sanitised output (lint rule). |
| Malicious uploads | Client type and size checks for feedback, and backend content sniffing and limits. SVG is never accepted. Private files are served only through expiring URLs. |
| Clickjacking | `frame-ancestors 'none'`, except the CMS preview route, which allows its own admin origin only |
| Secret exposure | Server-only env variables are never prefixed `NEXT_PUBLIC_` and are validated in `env.ts`. `bff` is `server-only`. A CI check scans client bundles for known secret names. |
| Sensitive data in logs | The logging rules of §36.3 |

### 31.2 Cookies

- All session cookies are `__Host-`, `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, and sealed with JWE.
- The only non-HttpOnly cookie is the storefront `currency` preference, alongside the consent state and theme preference, which are not sensitive.

### 31.3 Content Security Policy and Headers

A nonce-based CSP is set in `proxy.ts` per request (Next 16 CSP guide).

| Directive | Admin apps, affiliate portal | Storefront | Website |
|---|---|---|---|
| `default-src` | `'self'` | `'self'` | `'self'` |
| `script-src` | `'self' 'nonce-…' 'strict-dynamic'` | `'self' 'nonce-…' 'strict-dynamic'`, plus Google Tag Manager, Google Analytics and Meta after consent | As the storefront, with the platform's analytics |
| `connect-src` | `'self'` and `wss://ROOT` | `'self'`, `wss://ROOT` and analytics endpoints | `'self'` and analytics |
| `img-src` | `'self' data: https://cdn.ROOT` | Plus analytics pixels | Plus analytics pixels |
| `frame-ancestors` | `'none'` | `'none'` | `'none'` |
| `form-action` | `'self'` | `'self'` and the payment gateway hosts | `'self'` and the payment gateway hosts |

Every app also sets:

- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` (at the edge);
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- a `Permissions-Policy` denying camera, microphone and geolocation, except camera on `/pos` for barcode scanning.

### 31.4 Server and Client Boundary

| Server-only | Client-safe |
|---|---|
| `LARAVEL_INTERNAL_URL`, `SESSION_SECRET`, `SESSION_SECRET_PREVIOUS`, `REDIS_URL`, `REVALIDATE_SECRET`, `SENTRY_AUTH_TOKEN`, `OTEL_EXPORTER_OTLP_ENDPOINT` | `NEXT_PUBLIC_ROOT_DOMAIN`, `NEXT_PUBLIC_REVERB_KEY`, `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME`, `NEXT_PUBLIC_SENTRY_DSN`, `NEXT_PUBLIC_APP_ENV` |
| `packages/bff`, `src/server/*`, route handlers | `packages/ui`, feature components |

The Reverb app key is public by design; the secret never leaves the backend. OAuth client secrets live only in the backend. The frontend never sees them.

### 31.5 Permissions and URL Manipulation

- Changing an id in a URL leads to the backend's 404 or 403, handled by §30.
- The frontend never fetches data for a record the user cannot see "just to check".
- Hidden UI does not stand in for a backend check. A security review verifies that each gated action has a backend guard, by cross-checking the route manifest.

### 31.6 `proxy.ts` Responsibilities

Each app has one `proxy.ts`. It must stay light: no data fetching.

1. Validate the request host shape for the app (§7.4), returning 404 on failure.
2. Set the CSP nonce and security headers.
3. Storefront: the `/admin` convenience redirect (§15.4).
5. Optimistic auth redirect: a protected route with no session cookie goes to login. This is a UX shortcut only; real checks happen in the BFF and Laravel.

---

## 32. Performance

### 32.1 Budgets

| Metric (p75, mobile, 4G) | Storefront | Website | Admin apps |
|---|---|---|---|
| LCP | ≤ 2.0 s | ≤ 2.0 s | ≤ 2.5 s |
| INP | ≤ 200 ms | ≤ 200 ms | ≤ 200 ms |
| CLS | ≤ 0.05 | ≤ 0.05 | ≤ 0.1 |
| First-load client JavaScript (compressed) | ≤ 130 kB, product page ≤ 170 kB | ≤ 120 kB | ≤ 250 kB shell, ≤ 150 kB per route |
| TTFB with a cache hit | ≤ 200 ms | ≤ 200 ms | n/a |

The CI bundle check (§38.3) fails a pull request that exceeds a budget by more than 5%.

### 32.2 Techniques

- **Server Components by default.** A component is a client component only for interactivity.
- **Minimal providers.** The root layout of the storefront and website mounts no client providers except the consent manager. TanStack Query providers mount in the client islands and account layout that need them.
- **Streaming.** Suspense boundaries around personal islands and secondary admin panels. `loading.tsx` per route.
- **Caching.** Per-tenant cached data (§14.4), with a shared remote cache handler across replicas.
- **Prefetch.** `<Link>` prefetch for catalogue navigation. `prefetch={true}` only on links whose cached content depends on search params.
- **Code splitting.** Route-level by default. `next/dynamic` for Tiptap, charts, Echo, barcode scanning and the POS offline module.
- **Request budget.** An admin page issues at most 8 API requests on first load, including the snapshot and notices. Typeahead waits for 2 characters and 300 ms. Lookups are cached for 30 minutes.
- **Fonts.** `next/font` only; at most two families per storefront theme; `display: swap`.

### 32.3 Images

- A custom `next/image` loader for the media host returns the backend's pre-generated conversion URL for the requested width, so there is no runtime optimisation cost on Next servers. `next.config.ts` sets `images.loader: 'custom'` with `loaderFile` pointing at this loader.
- The product LCP image uses `priority` and has explicit dimensions, so no image causes layout shift.
- `remotePatterns` is limited to the media host.

---

## 33. Accessibility

**Standard:** WCAG 2.2 AA for every app. It is a release criterion, not an enhancement.

| Area | Requirement |
|---|---|
| Semantics | Landmarks (`header`, `nav`, `main`, `footer`), one `h1` per page, heading order, native elements first. Base UI primitives provide ARIA for composite widgets. |
| Keyboard | Everything is operable by keyboard. Focus order follows visual order. A "Skip to content" link. Command palette `⌘K` / `Ctrl+K`. POS shortcuts are documented on screen. |
| Focus | A visible focus ring (token `--ring`). Dialogs trap focus and return it on close. Route changes move focus to the page `h1`. |
| Forms | Every input has a label. Help and error text are linked with `aria-describedby`. Errors are announced (`role="alert"` for form-level errors). After a failed submit, focus moves to the first invalid field. |
| Tables | Semantic `<table>`, `scope` on headers, `aria-sort` on sortable columns, a row-action menu reachable by keyboard, and a selection count announced in a live region |
| Dialogs and toasts | Toasts never carry the only copy of critical information. Destructive confirmations focus Cancel first. |
| Loading | `aria-busy` on regions being updated. Skeletons are hidden from assistive technology, with a live "Loading…" text. |
| Colour | Token contrast is checked in CI. Storefront foreground colours are computed for AA (§23.3). Meaning is never conveyed by colour alone; badges have text. |
| Motion | `prefers-reduced-motion` disables non-essential animation (`tw-animate-css` classes wrapped by `motion-safe:`). |
| Language and direction | `lang` from the locale, and `dir` from RTL (§34) |
| Testing | axe on every component test and every Playwright page visit, failing on serious and critical issues. A manual screen-reader pass (NVDA and VoiceOver) on checkout, login, the admin list-detail-edit flow and POS before each major release. |

---

## 34. Internationalisation and Formatting

### 34.1 Languages

| App | UI strings | Content |
|---|---|---|
| `storefront` | next-intl, locale from the storefront config `formatting.locale`. English is shipped in v1, and message catalogues are structured for more locales. | Tenant content as authored |
| `platform-web` | next-intl, platform default locale | Landlord CMS as authored |
| Admin apps, affiliate portal | English only in v1 (§43) | n/a |

There is no locale in the storefront URL, because one store has one configured locale in the backend. A future multi-locale store is a backend change first.

### 34.2 RTL

`dir="rtl"` is set when `rtl_enabled` is true. `components.json` has `rtl: true`, and components use logical Tailwind utilities (`ms-*`, `ps-*`, `start-*`).

### 34.3 Formatting (`packages/format`)

All formatting is driven by backend settings, and nothing is duplicated as frontend configuration.

| Kind | Source | Rule |
|---|---|---|
| Money | Decimal string plus currency; `decimal_digits` and `default_currency_position` (storefront config) | `Intl.NumberFormat` with the currency. The string is parsed into a decimal without floating-point arithmetic. The frontend never adds amounts. |
| Quantity | Decimal string (3 decimals) | Trailing zeros trimmed for display |
| Date and time | ISO strings; `display.timezone`, `date_format`, `time_format` (staff `me`, storefront config) | Formatted in the tenant timezone with date-fns and `@date-fns/tz`. Date-only values are never shifted by a timezone. |
| Input date-times | User input in the tenant timezone | Converted to ISO 8601 with offset before sending |
| Relative time | `Intl.RelativeTimeFormat` | Used for "Updated 2 minutes ago" |

Staff personal preferences (`PATCH /api/admin/auth/preferences`: date and time format) override tenant defaults in the admin. The API applies them in `display`, and the frontend reads `display`.

---

## 35. Testing Strategy

### 35.1 Layers

| Layer | Tooling | Scope | Runs |
|---|---|---|---|
| Unit | Vitest | `format`, `access` (gates against a fixture snapshot and manifest), `api-client` (envelope, errors, query serialiser, idempotency), `bff` (host validation, allow-list normalisation, sealing, CSRF), custom-field schema building, nav visibility | Every pull request |
| Component | Vitest, Testing Library, jsdom, MSW, axe | `ui` components, `DataTable`, `BulkBar`, `ImportDialog`, forms with `applyApiErrors`, `Can` gates, module-state pages, limit dialog, custom-field inputs, CMS renderers | Every pull request |
| Integration (BFF) | Vitest with an undici mock agent | `upstream()` sets `Host`, forwards headers, filters response headers, seals tokens, strips `guest_token`, maps timeouts to `upstream_unavailable` | Every pull request |
| Contract | Vitest against a seeded backend (Docker `tenant-ecommerce-api` at the pinned commit) | Every response overlay entry validated against a real response (§13.2); the route, permission and module triple check (§13.4) | Pull requests touching `contract` or features; nightly |
| End to end | Playwright against the full stack (edge, apps, seeded backend) | The critical flows below | Nightly and before release; the smoke subset on every pull request to `main` |
| Visual | Playwright screenshots of `/ui` and key pages | Theme and dark mode regressions | Nightly |
| Performance | Lighthouse CI on the storefront and website | §32.1 budgets | Every pull request touching those apps |

### 35.2 Component Test Rules

- Every `ui` component: render, keyboard interaction and axe.
- Every form: success, a 422 mapped to fields, an unknown field error shown at form level, and a 403 limit that opens the dialog.
- MSW handlers in `packages/testing` build real envelopes (`envelope.ok(data, meta)`, `envelope.error(code, status, details, errors)`), so tests exercise the real parsing.

### 35.3 End-to-End Flows

| Flow | Apps |
|---|---|
| Tenant signup through verification, payment (Paystack test mode) and provisioning to first admin login | `platform-web`, `tenant-admin` |
| Staff login, refresh (clock-advanced), logout across tabs | `tenant-admin` |
| Permissions: a clerk sees a reduced navigation; a direct URL shows the access page; a forbidden action returns 403 | `tenant-admin` |
| Module activation: enable and disable with requirements, locked module read-only view | `tenant-admin` |
| Limit reached: create at the limit shows the dialog | `tenant-admin` |
| Product create with custom fields and media; bulk deactivate with a partial failure | `tenant-admin` |
| Import products with one bad row, reaching `completed_with_errors` with the error table | `tenant-admin` |
| Export orders as xlsx; wait; download | `tenant-admin`, `platform-admin` |
| Storefront browse, filter, add to cart, guest checkout, test payment, payment return, order page | `storefront` |
| Checkout conflict: price changed between quote and order | `storefront` |
| Customer register and login with guest cart merge | `storefront` |
| Social login (Google): a provider stub serves the authorisation and token endpoints through the relay; new account, existing verified account, `social_email_unverified` | `storefront`, `platform-web` |
| Subscription past due: read-only banner, write blocked | `tenant-admin` |
| Support chat realtime between customer and staff | `storefront`, `tenant-admin` |
| POS sale, cash and offline queue with sync | `tenant-admin` |
| Platform admin: suspend a tenant, and the storefront shows unavailable | `platform-admin`, `storefront` |
| Tenant isolation: tenant A's session cookie replayed on tenant B's admin host is rejected | `tenant-admin` |

The suite does not end-to-end-test every endpoint. Endpoint behaviour is covered by the backend's own test suite, and the frontend's by component and contract tests.

---

## 36. Observability

### 36.1 Signals

| Signal | Tool | Detail |
|---|---|---|
| Client errors | Sentry (browser SDK) | Unhandled errors and `ApiError`s with status ≥ 500 or a frontend-only code, tagged with app, `apiHost` (hashed), route, request ID and release |
| Server errors | Sentry (Node SDK), OpenTelemetry traces | Route handler and Server Component errors. BFF upstream spans carry the method, path template, status and duration. |
| Request correlation | `X-Request-Id` | Taken from the edge and passed to Laravel. Shown in error UI (§30.1) and attached to Sentry events, so one ID links edge, frontend and backend logs. |
| Web Vitals | `useReportWebVitals` to the metrics endpoint | LCP, INP, CLS and TTFB per app and route template, sampled at 10% |
| Business flow events | Server-side structured logs | Login success and failure by actor, social login outcome codes, checkout steps, payment return outcome, import and export start and finish. Counts only, no personal data. |
| Uptime | Synthetic checks | `/` on the website, a demo storefront home and product page, and the admin login pages |

### 36.2 Dashboards and Alerts

- Error rate per app over 1% for 5 minutes.
- `upstream_unavailable` spikes.
- 429 rate per tenant.
- Payment-return pending rate.
- p75 LCP regression over budget for a day.

### 36.3 What Is Never Logged

- Passwords, tokens (Sanctum, guest, OAuth `code`, `state`), session cookies or `Authorization` headers.
- OAuth client secrets.
- Payment credentials.
- Full request or response bodies from the BFF.
- Customer personal data (names, emails, addresses, phones).

The Sentry `beforeSend` hook scrubs these keys and query parameters: `token`, `code`, `state`, `email`, `password`, `signature` and `link`. Breadcrumbs record paths as route templates, not concrete URLs with ids.

---

## 37. Configuration and Environment Variables

Each app validates its environment at boot with a Zod schema in `src/env.ts`. The app refuses to start when a variable is missing or invalid.

### 37.1 Shared (All Apps)

| Variable | Scope | Purpose |
|---|---|---|
| `APP_ENV` | server | `local`, `staging`, `production` |
| `ROOT_DOMAIN` | server | Must equal the backend `PLATFORM_ROOT_DOMAIN` |
| `NEXT_PUBLIC_ROOT_DOMAIN` | client | The same value, for building links |
| `LARAVEL_INTERNAL_URL` | server | The private address of Laravel, for example `http://laravel.internal:8000` |
| `SESSION_SECRET`, `SESSION_SECRET_PREVIOUS` | server | JWE keys (32-byte base64) |
| `SENTRY_DSN`, `NEXT_PUBLIC_SENTRY_DSN`, `SENTRY_AUTH_TOKEN` | server, client, build | Error tracking |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | server | Traces |
| `MAX_UPLOAD_MB` | server | 25; matches the edge and Laravel |
| `LOCAL_EDGE_PORT` | server | Local only (§38.6) |

### 37.2 Per App

| App | Variables |
|---|---|
| `platform-web` | `TENANT_ADMIN_URL` (a template with `{slug}` for the sign-up done link, §24.3), `PLATFORM_ADMIN_URL`, `AFFILIATE_PORTAL_URL` (links), `REDIS_URL` (remote cache), `NEXT_PUBLIC_ANALYTICS_ID` (optional) |
| `platform-admin` | `NEXT_PUBLIC_REVERB_KEY`, `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME`, `PLATFORM_WEBSITE_URL` (the registration redirect, §24.3) |
| `affiliate-portal` | `PLATFORM_WEBSITE_URL` (referral link base) |
| `tenant-admin` | `NEXT_PUBLIC_REVERB_*`, `SANCTUM_TTL_MINUTES` (fallback expiry) |
| `storefront` | `NEXT_PUBLIC_REVERB_*`, `REDIS_URL`, `REVALIDATE_SECRET` (BG-13), `MEDIA_HOST` (image loader), `PAYMENT_FORM_ACTION_HOSTS` (CSP) |

OAuth configuration lives only in the backend: `GOOGLE_*` and `FACEBOOK_*`, with the redirect URIs set to `https://ROOT/oauth/{provider}/callback`. The frontend needs no OAuth variables.

### 37.3 Backend Values the Frontend Deployment Requires

| Backend variable | Value |
|---|---|
| `PLATFORM_ADMIN_URL` | `https://platform.ROOT` |
| `AFFILIATE_PORTAL_URL` | `https://affiliates.ROOT` |
| `PLATFORM_WEBSITE_URL` | `https://ROOT` |
| `PLATFORM_ADMIN_ORIGINS` | Empty in production (the BFF makes browser CORS unnecessary) |
| `GOOGLE_REDIRECT_URI`, `FACEBOOK_REDIRECT_URI` | `https://ROOT/oauth/google/callback`, `https://ROOT/oauth/facebook/callback` |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | The edge host (`ROOT`), 443, https |
| `TENANT_ADMIN_URL` | `https://{slug}.admin.{root}` (D-141) |

Turborepo `env` and `globalEnv` list these variables per task, so the build cache varies correctly.

---

## 38. Build, CI, Deployment and Local Development

### 38.1 Build

- Every app builds with `output: 'standalone'`, into one container image per app (Node 22 LTS).
- `transpilePackages` lists the workspace packages each app uses.
- `cacheComponents: true` on `storefront` and `platform-web`; `cacheHandlers.remote` points at the Redis handler module.
- `turbo.json` adds tasks `test`, `test:contract`, `test:e2e`, `contract:generate` and `check:bundle`, with correct `dependsOn` and `outputs`.

### 38.2 Repository Scripts

| Script | Does |
|---|---|
| `pnpm dev` | All apps and the local edge (§38.6) |
| `pnpm dev --filter tenant-admin...` | One app and its packages |
| `pnpm contract:pull`, `pnpm contract:generate`, `pnpm contract:gaps` | §13 |
| `pnpm lint`, `pnpm typecheck`, `pnpm test`, `pnpm test:e2e` | Quality gates |

### 38.3 CI Pipeline

Every pull request runs:

1. Install with a frozen lockfile.
2. `contract:generate`, failing on a diff.
3. `turbo run lint typecheck test --affected`.
4. `turbo boundaries` (§5.6).
5. Build the affected apps.
6. Bundle-budget check.
7. Lighthouse CI for affected public apps.
8. Contract tests when `contract` or features change.
9. A client-bundle secret scan.

Nightly runs execute the full end-to-end and visual suites. Turborepo remote caching is enabled with a signed cache.

### 38.4 Deployment

- **Independent apps.** Each app deploys independently. A change to a package redeploys only the apps that depend on it (`--affected`).
- **Environments:**
  - **Preview:** per pull request, for public apps, against the staging backend with a staging root domain `pr-{n}.staging.ROOT` (wildcard DNS).
  - **Staging:** full stack, and the nightly end-to-end target.
  - **Production.**
- **Rollout.** Rolling deploy behind the edge with health checks (`/api/health` route handler per app, returning 200 without calling Laravel). Rollback redeploys the previous image tag, which the release manifest records per app.
- **Scaling.** At least 2 replicas per app in production. The storefront scales on CPU and request rate, and all replicas share the Redis cache handler.
- **Custom domains.** No frontend deployment step is needed. The edge issues certificates on demand, and the storefront serves any host that Laravel resolves.
- **Contract coupling.** The backend and frontend release independently. The frontend pins a backend contract version (§13.3). A backend release that breaks the pinned contract is caught by the contract tests before the frontend upgrades.

### 38.5 Caching Infrastructure

- **Redis** (a separate logical database from Laravel's) backs `cacheHandlers.remote` for the storefront and website.
- **Keys and tags.** Cache keys are namespaced `fe:{app}:`. Tags follow §14.4.
- **Revalidation.** A `revalidateTag(tag, 'max')` call reaches all replicas through the shared handler.

### 38.6 Local Development

- **The edge runs locally.** Local development reproduces the edge routing table of §7.2 with Caddy, because tenant resolution by host is the architecture and cannot be simulated with ports.
- **Root domain:** `ROOT = ecommerce.localhost`. Browsers resolve `*.localhost` to loopback with no hosts-file edits. Caddy serves locally trusted TLS (`tls internal`) on `LOCAL_EDGE_PORT` (default 443; use 8443 when another local server, such as Laravel Herd, already binds 443).
- **Backend:**
  - served by `php artisan serve --port=8000` (the PHP built-in server accepts any `Host`), which is the `LARAVEL_INTERNAL_URL`;
  - `.env` sets `PLATFORM_ROOT_DOMAIN=ecommerce.localhost`, `CENTRAL_DOMAINS=ecommerce.localhost`, `PLATFORM_ADMIN_URL=https://platform.ecommerce.localhost`, `AFFILIATE_PORTAL_URL=https://affiliates.ecommerce.localhost` and `PLATFORM_WEBSITE_URL=https://ecommerce.localhost`.
  - The current backend `.env` defaults (ports 3000, 3001 and 3002) collide with the frontend dev ports and are replaced by these host URLs.
- **App ports behind the edge:** platform-admin 3000, tenant-admin 3001, storefront 3002, platform-web 3003, affiliate-portal 3004.
- **Hosts:** `https://ecommerce.localhost`, `https://platform.ecommerce.localhost`, `https://affiliates.ecommerce.localhost`, `https://{slug}.admin.ecommerce.localhost` and `https://{slug}.ecommerce.localhost`.
- **Without the edge.** For work on one app, the app can run on its own port against Laravel Herd: `LARAVEL_INTERNAL_URL=http://127.0.0.1` (Herd's nginx serves every `*.{root}` host, and the BFF sets `Host` itself), `DEV_TENANT_SLUG={slug}` maps `localhost` to that store, and `INSECURE_COOKIES=true` drops the `__Host-` prefix and `Secure` so cookies work over plain http. Both variables are honoured only when `APP_ENV=local` and refused in production (`src/env.ts`).
- **Several stores without the edge.** tenant-admin also accepts `DEV_ADMIN_HOST=admin.localhost`. The BFF then maps `http://{slug}.admin.localhost:3001` to the store `{slug}.{ROOT}`, so any store's admin opens on its own host without Caddy. The backend's `TENANT_ADMIN_URL` is set to that pattern locally, so email links match. The same local-only guard applies.
- **Mock mode.** `pnpm dev:mock` runs any app against the MSW handlers of `packages/testing` for UI work without a backend.
- **Queues.** `php artisan queue:work` runs so imports, exports and notifications complete.

---

# Part VI: Maps, Gaps and Plan

## 39. Repository Tree

```
tenant-ecommerce-frontend/
├── apps/
│   ├── platform-web/                 ROOT: website, signup, OAuth relay
│   │   └── src/
│   │       ├── app/
│   │       │   ├── (marketing)/      /, pages/[slug], pricing, blog, faq, legal/[type], contact
│   │       │   ├── (signup)/         signup, register/verify, signup/payment, signup/status
│   │       │   ├── oauth/[provider]/callback/route.ts
│   │       │   ├── bff/              api/[...path], (no sessions)
│   │       │   ├── robots.ts
│   │       │   └── layout.tsx
│   │       ├── features/             cms, pricing, signup, affiliate-referral, oauth-relay, seo
│   │       ├── server/data/          cached landlord data functions
│   │       ├── shell/  config/  env.ts  proxy.ts
│   ├── platform-admin/               platform.ROOT
│   │   └── src/
│   │       ├── app/
│   │       │   ├── (auth)/           login, forgot-password, reset-password, verify-email
│   │       │   ├── (console)/        dashboard, tenants, tenant-registrations, database-servers, plans,
│   │       │   │                     subscriptions, payment-transactions, platform-coupons, platform-commissions,
│   │       │   │                     payment-gateways, affiliates, affiliate-referrals, affiliate-commissions,
│   │       │   │                     affiliate-payouts, module-notices, platform-support, exports, cms,
│   │       │   │                     legal-documents, platform-users, notification-templates, platform-settings
│   │       │   └── bff/              api/[...path], auth/[action], session, broadcasting/auth
│   │       ├── features/             one folder per landlord code module (§40.1)
│   │       └── shell/  server/  config/  env.ts  proxy.ts
│   ├── affiliate-portal/             affiliates.ROOT
│   │   └── src/app/{(auth),(portal),bff}/ …  features/ shell/ config/ env.ts proxy.ts
│   ├── tenant-admin/                 {slug}.admin.ROOT
│   │   └── src/
│   │       ├── app/
│   │       │   ├── (auth)/           login, forgot-password, reset-password
│   │       │   ├── (staff)/          dashboard, orders, …, settings/*, billing/callback, exports, imports
│   │       │   ├── seller/(auth)/    seller/login, register, forgot-password, reset-password
│   │       │   ├── seller/(portal)/  seller, seller/products, orders, ledger, payouts
│   │       │   ├── pos/              full-screen terminal
│   │       │   ├── kitchen/          full-screen display
│   │       │   ├── ui/               component gallery (non-production)
│   │       │   └── bff/              staff/api/[...path], seller/api/[...path], auth/[action], session, broadcasting/auth
│   │       ├── features/             one folder per tenant code module (§40.1)
│   │       └── shell/  server/  config/  env.ts  proxy.ts
│   └── storefront/                   {slug}.ROOT and custom domains
│       └── src/
│           ├── app/
│           │   ├── (shop)/           /, products, categories, brands, tags, search, pages, blog, faq, flash-sales,
│           │   │                     contact, gift-cards, careers, book, seller, orders/[order], orders/[order]/payment-return
│           │   ├── (checkout)/       cart, checkout
│           │   ├── account/          login, register, orders, returns, wishlist, …, privacy, export
│           │   ├── auth/social/[provider]/callback/route.ts
│           │   ├── verify-email/  reset-password/  forgot-password/
│           │   ├── bff/              api/[...path], auth/[action], social/[provider]/start, session, broadcasting/auth
│           │   └── robots.ts
│           ├── features/             catalog, cart, checkout, payment-return, account, auth, social-login, cms,
│           │                         support, gift-cards, careers, booking, reviews, wishlist, seo, consent
│           ├── themes/               default, minimal, bold, classic
│           └── server/data/  shell/  config/  env.ts  proxy.ts
├── packages/
│   ├── contract/                     bundle/, contract.lock.json, scripts/, src/generated/**, src/responses/**, src/schemas/**
│   ├── api-client/                   client factories, envelope, errors, query serialiser, idempotency, upload
│   ├── bff/                          tenant context, sealing, upstream, allow-list, csrf, route-handler factories
│   ├── access/                       snapshot types, gates, Can, useCan
│   ├── admin-kit/                    shell, nav engine, data table, bulk bar, import/export UI, CRUD templates,
│   │                                 forms (applyApiErrors), kpi grid, cms editor, support conversation view
│   ├── custom-fields/
│   ├── cms-render/
│   ├── realtime/
│   ├── format/
│   ├── testing/                      msw handlers, envelope factories, playwright fixtures
│   ├── ui/                           (existing) styles, components, icons, hooks, lib
│   ├── eslint-config/                (existing) + boundary rules
│   └── typescript-config/            (existing)
├── tooling/
│   └── edge/Caddyfile.local          local edge (§38.6)
├── e2e/                              Playwright suites (§35.3)
├── package.json  pnpm-workspace.yaml  turbo.json  tsconfig.json
└── AGENTS.md  README.md
```

---

## 40. Module Mapping and API Coverage Matrix

### 40.1 Backend Module to Frontend Mapping

A backend code module maps to one feature folder in each app that serves its actor. The same backend module can appear in several apps, but feature code is never shared between them (§5.5). Shared concerns go to packages only when they are domain-neutral: tables, forms, custom fields and rendering.

| Backend code module | Feature keys | Apps and feature folders | Shared packages used |
|---|---|---|---|
| Auth | core | every app: `auth`; storefront: `social-login` | `bff`, `api-client` |
| Tenancy (tenants, domains, registration, onboarding) | core | platform-web `signup`; platform-admin `tenancy`; tenant-admin `domains`, `onboarding` | `admin-kit` |
| Plans, Billing | core | platform-web `pricing`; platform-admin `plans`, `billing`; tenant-admin `billing` | `admin-kit`, `format` |
| Affiliates | landlord | platform-web `affiliate-referral`; platform-admin `affiliates`; affiliate-portal (whole app) | `admin-kit` |
| Access, Users | core | platform-admin `platform-users`; tenant-admin `users`, `roles` | `access` |
| Settings, Messaging, Notifications | core, `custom_email`, `whatsapp` | platform-admin `settings`, `notifications`; tenant-admin `settings`, `messaging`, `notifications` | `admin-kit` |
| Dashboard, Reports | core, `advanced_reporting` | platform-admin `dashboard`; tenant-admin `dashboard`, `reports`; affiliate-portal `dashboard` | `admin-kit` (`KpiGrid`) |
| Catalog, Reviews, Currency, Tax | core, `multi_currency` | tenant-admin `catalog`, `reviews`, `currencies`, `tax`; storefront `catalog`, `reviews` | `custom-fields`, `format` |
| Inventory | core | tenant-admin `inventory` | `admin-kit` |
| Customers | core | tenant-admin `customers`; storefront `account` | `custom-fields` |
| Cart, Orders, Payments, Shipping, Returns, Documents | core, `installments` | tenant-admin `orders`, `payments`, `shipping`, `returns`, `documents`; storefront `cart`, `checkout`, `payment-return`, `account` | `format`, `custom-fields` |
| Promotions, GiftCards, RewardPoints | core, `gift_cards`, `reward_points` | tenant-admin `promotions`, `gift-cards`, `reward-points`; storefront `cart`, `gift-cards`, `account` | `admin-kit` |
| Cms, Seo, Legal | core, `content_marketing` | platform-web `cms`; platform-admin `cms`, `legal`; tenant-admin `cms`; storefront `cms`, `seo` | `cms-render`, `admin-kit/cms` |
| CustomFields | core | tenant-admin `custom-fields` | `custom-fields` |
| Imports, Exports | core | tenant-admin `imports`, `exports`; platform-admin `exports`; storefront `account` (data export) | `admin-kit` |
| Support, PlatformSupport | `support`; core | tenant-admin `support`, `platform-support`; platform-admin `platform-support`; storefront `support` | `realtime`, `admin-kit/support` |
| ModuleNotices, Modules | core | platform-admin `module-notices`, `tenancy` (overrides); tenant-admin `modules` | `access` |
| Pos | `pos` | tenant-admin `pos` | `format` |
| Purchasing | `purchasing` | tenant-admin `purchasing` | `admin-kit` |
| Accounting, Expenses | `accounting`, `expenses` | tenant-admin `accounting`, `expenses` | `admin-kit` |
| Hr | `hr`, `hr_payroll`, `hr_recruitment` | tenant-admin `hr/{core,payroll,recruitment}`; storefront `careers` | `admin-kit` |
| Marketplace | `marketplace` | tenant-admin `marketplace` (staff) and `seller-portal`; storefront `seller` page | `admin-kit` |
| SalesQuotations, SalesAgents, ProductSubscriptions, Installments, BackInStock | as named | tenant-admin features of the same names; storefront `account`, `catalog` widgets | `admin-kit` |
| Approvals, AiAssistant, Projects, Manufacturing | `approval_workflows`, `ai_assistant`, `project_management`, `manufacturing` | tenant-admin features of the same names | `admin-kit` |
| Restaurant, Booking, Repair | verticals | tenant-admin `restaurant` (+ `kitchen`), `booking`, `repair`; storefront `booking`, `account` | `admin-kit` |
| Integrations: WooCommerce, SocialCommerce | integrations | tenant-admin `woocommerce`, `social-commerce` | `admin-kit` |
| Shipping driver routes | core | none (driver app out of scope) | none |

### 40.2 API-to-Frontend Coverage Matrix

Key:

- **Auth:** P platform user, A affiliate, S staff, L seller, C customer, G guest, — public.
- **Permission** is derived per route. "own" means self-service.
- **Async:** Q means queued with status polling.
- **Status:** Covered means the spec defines the frontend strategy. Partial or Gap means it depends on the named backend gap.

| Backend domain | API capability | App | Frontend feature | Auth | Permission | Module gate | UI pattern | Async | Import | Export | Bulk | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Registration | `/api/register*` | platform-web | signup | — | none | none | Wizard | Q (provisioning) | no | no | no | Covered |
| Plans | `/api/admin/plans*` | platform-admin | plans | P | derived | none | CRUD + tabs | no | no | no | no | Covered |
| Tenants | `/api/admin/tenants*` | platform-admin | tenancy | P | derived | none | List, detail tabs, actions | Q (tenant export) | no | yes | no | Covered |
| Subscriptions, transactions, coupons | `/api/admin/{subscriptions,payment-transactions,platform-coupons}*` | platform-admin | billing | P | derived | none | List, detail, metrics strip | no | no | yes | no | Covered |
| Commissions | `/api/admin/platform-commissions*`; tenant `/api/admin/billing/commissions` | platform-admin, tenant-admin | billing | P, S | derived | none | List, waive; tenant list | no | no | no | no | Covered |
| Gateways | `/api/admin/payment-gateways*` | platform-admin | billing | P | derived | none | Settings forms | no | no | no | no | Covered |
| Affiliates (admin) | `/api/admin/affiliate*` | platform-admin | affiliates | P | derived | none | List, detail, actions, metrics | no | no | yes | no | Covered |
| Affiliates (portal) | `/api/affiliate/*` | affiliate-portal | whole app | A | own | none | Dashboard, lists, forms | no | no | no | no | Covered |
| Platform users | `/api/admin/platform-users*` | platform-admin | platform-users | P | derived | none | CRUD, roles | no | no | no | no | Covered (no refresh or password change: BG-10) |
| Landlord CMS, legal | `/api/admin/cms/*`, `/api/admin/legal-documents*` | platform-admin | cms, legal | P | derived | none | CMS editor | no | no | no | no | Covered (preview BG-16) |
| Platform settings, notifications | `/api/admin/platform-settings*`, `/notification-templates*` | platform-admin | settings, notifications | P | derived | none | Settings forms, matrix | no | no | no | no | Covered (inbox BG-08) |
| Helpdesk | `/api/admin/platform-support*` | platform-admin, tenant-admin | platform-support | P, S | derived | none | Conversations | Realtime | no | no | no | Covered |
| Platform exports | `/api/admin/exports*` (landlord) | platform-admin | exports | P | derived | none | Export centre | Q | no | yes | no | Covered |
| Dashboards | `/api/admin/dashboard*`, `…/metrics` | platform-admin, tenant-admin | dashboard | P, S | derived | per section | KPI grid | no | no | no | no | Covered |
| Staff auth and session | `/api/admin/auth/*` | tenant-admin | auth | S | none | none | Login, refresh | no | no | no | no | Covered |
| Modules | `/api/admin/modules*` | tenant-admin | modules | S | derived (enable, disable) | none | Catalogue | no | no | no | no | Covered |
| Onboarding | `/api/admin/onboarding` | tenant-admin | onboarding | S (owner) | none | none | Checklist card | no | no | no | no | Covered |
| Products | `/api/admin/products*` | tenant-admin | catalog | S | derived | none | CRUD, media, variants | no | yes | yes | yes | Covered |
| Categories, brands, tags, options, units | `/api/admin/{categories,…}` | tenant-admin | catalog | S | derived | none | CRUD, dialogs | no | yes (categories) | yes (categories) | yes (categories) | Covered |
| Reviews, questions | `/api/admin/{reviews,product-questions,product-answers}` | tenant-admin | reviews | S | derived | none | Moderation list | no | no | no | yes | Covered |
| Inventory | `/api/admin/{warehouses,inventory,stock-*}` | tenant-admin | inventory | S | derived | none | Lists, documents | no | yes (stock) | yes | no | Covered |
| Customers | `/api/admin/{customers,customer-groups}` | tenant-admin | customers | S | derived | none | CRUD | no | yes | yes | yes | Covered |
| Orders, payments, shipments, returns | `/api/admin/{orders,order-payments,shipments,delivery-assignments,drivers,returns,return-reasons}` | tenant-admin | orders, payments, shipping, returns | S | derived | none | List, detail, transitions | no | no | yes | yes (orders) | Covered |
| Promotions | `/api/admin/{promotions,coupons,flash-sales}` | tenant-admin | promotions | S | derived | none | CRUD | no | no | yes (coupons) | yes (coupons) | Covered |
| Gift cards, reward points | `/api/admin/{gift-cards,reward-points}` | tenant-admin | gift-cards, reward-points | S | derived | `gift_cards`, `reward_points` | CRUD, issue | no | no | no | no | Covered |
| Tenant CMS | `/api/admin/cms/*` | tenant-admin | cms | S | derived | core, `content_marketing` | CMS editor | no | no | no | no | Covered |
| Custom fields | `/api/admin/custom-fields*` | tenant-admin | custom-fields | S | derived | core | Definition editor | no | no | no | no | Covered |
| Settings (store, storefront, domains, payments, tax, shipping, messaging, documents, currencies) | `/api/admin/{settings,storefront-settings,domains,payment-settings,tax-rates,shipping-*,sms-gateway-settings,whatsapp-settings,invoice-templates,barcode-settings,receipt-printers,currencies}` | tenant-admin | settings and subfeatures | S | derived | per route | Settings forms | no | no | no | no | Covered (media settings BG-12) |
| Billing | `/api/admin/billing/*` | tenant-admin | billing | S | derived | none | Plans, preview, checkout | Q (callback polling) | no | no | no | Covered |
| Imports | `/api/admin/imports*` | tenant-admin | imports | S | derived + per type | none | Import dialog, status | Q | yes | no | no | Covered |
| Exports | `/api/admin/exports*` | tenant-admin | exports | S | derived | none | Export centre | Q | no | yes | no | Covered |
| Notifications | `/api/admin/notifications*`, `/notification-preferences*`, `/notification-templates*`, `/notifications/matrix*` | tenant-admin | notifications | S | own, derived | none | Bell, inbox, preferences, matrix | no | no | no | no | Covered (mark all: BG-09) |
| Staff users, roles | `/api/admin/{users,roles,permissions}` | tenant-admin | users, roles | S | derived | none | CRUD, permission picker grouped by module | no | no | no | no | Covered |
| Support | `/api/admin/support/*`; `/api/support/*` | tenant-admin, storefront | support | S, C, G | derived; own | `support` | Inbox, conversation | Realtime | no | no | no | Covered |
| POS | `/api/admin/pos/*` | tenant-admin | pos | S | derived | `pos` | Full-screen terminal | Q (terminal charge) | no | no | no | Covered (offline snapshot BG-14) |
| Purchasing | `/api/admin/{suppliers,purchase-orders,supplier-*,quotation-requests,purchase-return*}` | tenant-admin | purchasing | S | derived | `purchasing` | CRUD, documents | no | no | yes | no | Covered |
| Accounting | `/api/admin/accounting/*` | tenant-admin | accounting | S | derived | `accounting` | Ledger screens, reports | no | no | yes (reports) | no | Covered |
| Expenses, income | `/api/admin/{expenses,income,*-categories}` | tenant-admin | expenses | S | derived | `expenses` | CRUD | no | no | yes | no | Covered |
| HR | `/api/admin/hr/*` | tenant-admin | hr | S | derived; own | `hr`, `hr_payroll`, `hr_recruitment` | CRUD, roster grid, review queues, payroll runs | no | no | no | no | Covered |
| Marketplace (staff) | `/api/admin/{sellers,seller-products,seller-groups}` | tenant-admin | marketplace | S | derived | `marketplace` | CRUD, approvals, payouts | no | no | no | no | Covered |
| Seller portal | `/api/seller/*` | tenant-admin | seller-portal | L | own | `marketplace` | Portal | no | no | no | no | Covered |
| Sales quotations, agents, product subscriptions, installments | `/api/admin/{sales-quotations,sales-quotation-requests,sales-agents,sales-agent-commissions,product-subscriptions,installment-plans}` | tenant-admin | same names | S | derived | own modules | CRUD | no | no | no | no | Covered |
| Approvals | `/api/admin/{approvals,approval-workflows}` | tenant-admin | approvals | S | derived | `approval_workflows` | Queue, workflow editor | no | no | no | no | Covered |
| AI assistant | `/api/admin/ai-assistant*` | tenant-admin | ai-assistant | S | derived | `ai_assistant` | Chat panel | no | no | no | no | Covered |
| Projects, manufacturing | `/api/admin/{projects,project-categories,bill-of-materials,work-orders}` | tenant-admin | projects, manufacturing | S | derived | own modules | CRUD, boards | no | no | no | no | Covered |
| Restaurant | `/api/admin/restaurant/*` | tenant-admin | restaurant, kitchen | S | derived | `restaurant` | Floor plan, tables, kitchen board | Polling | no | no | no | Covered |
| Booking | `/api/admin/{bookings,booking-staff}`; `/api/bookings` | tenant-admin, storefront | booking | S, C, G | derived | `booking` | Calendar, booking form | no | no | no | no | Covered |
| Repair | `/api/admin/repair-jobs*`; `/api/account/repair-jobs*` | tenant-admin, storefront | repair, account | S, C | derived; own | `repair` | Job board; customer approval | no | no | no | no | Covered |
| Integrations | `/api/admin/{woocommerce,social-commerce}*` | tenant-admin | woocommerce, social-commerce | S | derived | integration keys | Connection settings, sync runs | Q (sync) | no | no | no | Covered (OAuth connect: UD-23, backend open decision) |
| Storefront catalogue | `/api/{products,categories,brands,tags,flash-sales}*` | storefront | catalog | — | none | none | Server-rendered pages | no | no | no | no | Covered (view recording BG-15) |
| Storefront config, CMS | `/api/storefront/*`, `/api/cms/*` | storefront | shell, cms | — | none | per flag | Server-rendered | no | no | no | no | Covered |
| Cart, checkout, payments | `/api/cart*`, `/api/orders*`, `/api/payments/verify`, `/api/payment-methods`, `/api/shipping/methods` | storefront | cart, checkout, payment-return | C, G | own | none | Client islands | Q (payment verify) | no | no | no | Covered |
| Customer auth and social | `/api/auth/*`, `/api/account/social-accounts*` | storefront, platform-web | auth, social-login, oauth-relay | —, C | own | none | Forms, redirect flow | no | no | no | no | Covered |
| Customer account | `/api/account*`, `/api/wishlist*`, `/api/returns*`, `/api/notifications*`, `/api/quotation-requests*`, `/api/downloads/*` | storefront | account | C | own | per module | Account pages | Q (data export) | no | yes (own data) | no | Covered |
| Gift cards, careers, back in stock, reviews, questions | `/api/gift-cards/*`, `/api/careers/*`, `…/back-in-stock-alert`, `…/reviews`, `…/questions` | storefront | gift-cards, careers, catalog | —, C, G | none, own | per module | Forms | no | no | no | no | Covered |
| Driver | `/api/driver/*` | none | none | driver | n/a | n/a | n/a | n/a | n/a | n/a | n/a | Not required (§1.3) |
| Push tokens | `/api/{account,seller,driver}/push-tokens`, `/api/admin/push-tokens` | none in web v1 | none | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | Future (native apps) |

### 40.3 Frontend-to-Backend Gap Analysis

| Frontend requirement | Classification | Resolution |
|---|---|---|
| Resolve the tenant from the admin host via the slug | Supported | `Host: {slug}.ROOT` (§7.4) |
| Per-customer rate limiting and correct IPs through the BFF | Supported | BG-01 resolved (D-141) |
| Admin and seller links on the admin origin | Supported | BG-02 resolved (D-141) |
| Registration verification link on the website | Supported | BG-03 resolved (D-141) |
| Tenant id, slug and primary domain on the storefront | Supported | BG-04 resolved (D-141) |
| Realtime channel names for tenant support | Supported | Built from `tenant.id` (BG-04); BG-05 optional |
| Typed response data in OpenAPI | Supported (about 97%) | BG-06, resolved in D-142; overlay for the metrics, dashboard and platform-settings operations |
| Route manifest and registries as artefacts | Supported | BG-07, resolved in D-142 |
| Platform-user inbox | Backend contract gap | BG-08 |
| Mark all notifications read | Backend contract gap | BG-09 |
| Platform-user refresh and password change; per-actor token lifetimes | Backend contract gap | BG-10 |
| Storefront flags for every module with storefront surfaces | Supported | BG-11 resolved (D-141) |
| Upload endpoints for store logo, favicon, share image and variant image | Supported | BG-12, resolved in D-142 |
| Backend-triggered storefront revalidation | Future | BG-13 |
| POS offline catalogue snapshot | Future | BG-14 |
| Product view recording separate from cached pages | Partially supported | BG-15 |
| CMS draft preview | Future | BG-16 |
| Social-commerce OAuth connect | Not required now | Backend open decision UD-23; the token is pasted meanwhile |
| Unread notification count | Supported | `meta.unread_count` |
| Product, category and brand by slug | Supported | The routes accept slugs |
| Login expiry | Supported | `expires_at` in login responses |
| Platform-user `me` | Supported | `GET /api/admin/auth/me` |
| Reserved hosts | Supported | Chosen from the existing reserved slugs (§6.2) |

---

## 41. Backend Contract Gaps

Each gap is written so the backend can schedule it without further analysis. Priorities:

- **P0** blocks production launch.
- **P1** is needed for a complete v1 and has a working interim.
- **P2** improves correctness or UX and has an acceptable interim.
- **P3** is future.

Resolved gaps keep their section and number, with a **Status** row, so references stay stable. BG-01, BG-02, BG-03, BG-04 and BG-11 were resolved in backend decision D-141; BG-06, BG-07 and BG-12 in D-142; BG-05, BG-08, BG-09 and BG-10 in D-143.

### BG-01: Trust the BFF's Forwarded Client IP

| | |
|---|---|
| **Capability** | Correct client IP for rate limiting, legal-acceptance evidence, affiliate click and fraud checks, and logs |
| **Reason** | Laravel configures no trusted proxies (§3.1). Every request from a Next.js server therefore has that server's IP, so the whole storefront shares one `throttle:public` bucket (60/min) and one `auth-sensitive` bucket (5/min). Real traffic would be throttled, and one user's failed logins would lock out others. |
| **Required change** | `->withMiddleware(fn ($m) => $m->trustProxies(at: env('TRUSTED_PROXIES'), headers: Request::HEADER_X_FORWARDED_FOR \| Request::HEADER_X_FORWARDED_PROTO))`, with `TRUSTED_PROXIES` set to the edge and frontend server CIDRs. `X-Forwarded-Host` is **not** trusted: the BFF sets `Host` directly. |
| **Request, response** | No API shape change |
| **Auth, authorisation, tenant scope** | Unchanged |
| **Priority** | P0 |
| **Status** | **Resolved** in D-141: `TRUSTED_PROXIES`, applied by `App\Shared\Http\TrustedProxies` |

### BG-02: Tenant-Admin and Seller Link Base

| | |
|---|---|
| **Capability** | Email and callback links to the tenant admin and seller portal on their own origin |
| **Reason** | `FrontendUrl::tenantAdmin()` builds `{primary domain}{TENANT_ADMIN_PATH}`, and seller reset links use the storefront host. The frontend serves both at `{slug}.admin.ROOT` for origin isolation (ADR-07). |
| **Required change** | Add `TENANT_ADMIN_URL_TEMPLATE` (default `https://{slug}.admin.{root}`). `FrontendUrl::tenantAdmin()` and the seller reset link use it (the seller path stays `/seller/…`). Remove `TENANT_ADMIN_PATH`. |
| **Request, response** | Unchanged; only link values change |
| **Priority** | P1 |
| **Status** | **Resolved** in D-141: `TENANT_ADMIN_URL` and `FrontendUrl::sellerPortal()` |

### BG-03: Registration Verification Link Target

| | |
|---|---|
| **Capability** | The emailed verification link opens the signup flow on the website |
| **Reason** | `TenantRegistrationService` uses `FrontendUrl::platformAdmin('/register/verify', …)`. Registrants have nothing to do with the platform admin. |
| **Required change** | Use `FrontendUrl::website('/register/verify', …)`. |
| **Priority** | P1 |
| **Status** | **Resolved** in D-141 |

### BG-04: Tenant Identity in the Storefront Config

| | |
|---|---|
| **Capability** | Canonical URLs and host canonicalisation, admin redirects on custom domains, and tenant realtime channel names |
| **Reason** | `GET /api/storefront/config` and the staff `me` do not expose the tenant id, slug or primary domain. |
| **Required change** | Add `tenant: {id, slug, primary_domain}` to the storefront config. Add `tenant: {id, slug, primary_domain}` to the staff `me`. |
| **Request** | Unchanged |
| **Response** | `data.tenant = {"id": "…", "slug": "acme", "primary_domain": "shop.acme.com"}` |
| **Tenant scope** | The current tenant only. The id is not secret; channel authorisation stays server-side. |
| **Priority** | P1 |
| **Status** | **Resolved** in D-141. The config cache is cleared when the primary domain changes. |

### BG-05: Broadcast Channel Name on Conversation Resources

| | |
|---|---|
| **Capability** | Subscribing to the right channel without assembling names in the client |
| **Required change** | Add `broadcast_channel` (for example `private-tenant.{id}.support-conversation.{id}`) to tenant and platform support conversation resources. Add `inbox_channel` and `presence_channel` to the support inbox listing meta. |
| **Priority** | P2. With BG-04 the client can build names from the documented pattern, but the resource field removes the duplication. |
| **Status** | **Resolved** in D-143. The names are given in Laravel Echo form, without the `private-` or `presence-` prefix: pass `broadcast_channel` and `meta.inbox_channel` to `Echo.private()`, and `meta.presence_channel` to `Echo.join()`. |

### BG-06: Typed Response Schemas and Error Envelope in OpenAPI

| | |
|---|---|
| **Capability** | Generated response types instead of the hand-maintained overlay |
| **Reason** | `APIResponse::success($resource)` hides the resource from Scramble, so `data` is documented as `string` in 95% of operations. Errors are documented in Laravel's shape rather than the envelope. |
| **Required change** | A Scramble extension that types `APIResponse::success`, `created` and `accepted` with the wrapped `JsonResource` or array shape (including paginated `meta.pagination`), and a document transformer that replaces the error responses with the envelope schema (`meta.error_code` enum per route where known). |
| **Priority** | P1 |
| **Status** | **Resolved** in D-142. `data`, `meta.pagination` and `meta.links` are typed; every error response references `ErrorEnvelope`. `meta.error_code` is a plain string (the list is `error-codes.json`), not a per-route enum. About 35 operations stay untyped (§13.1). |

### BG-07: Contract Bundle Export

| | |
|---|---|
| **Capability** | Machine-readable route, module, limit, permission and error registries for the admin apps |
| **Required change** | `php artisan frontend:contract {--path=}` writes: |
| | `routes.{landlord,tenant}.json`: for each named route, `name`, `method`, `uri`, `module` (feature key or null), `permission` (the derived name or null), `wind_down`, `idempotency` and `usage_limit` (limit key or null) |
| | `modules.json` (from `config/modules.php`), `limits.json` (key, label, kind) |
| | `permissions.{landlord,tenant}.json`, `error-codes.json` (code, status, owning module) |
| | `storefront.json` (themes, fonts) |
| | The OpenAPI documents |
| **Priority** | P1 |
| **Status** | **Resolved** in D-142, with the fields listed in §13.4. `--path` takes an absolute directory and `--openapi` re-exports the documents first. The bundle is a build artefact, not committed in the backend. |

### BG-08: Platform-User Notification Inbox

| | |
|---|---|
| **Capability** | In-app notifications for platform users. Landlord templates targeting `platform_user` default to the `database` channel, but there is no route to read them. |
| **Required change** | `GET /api/admin/notifications` (paginated, `meta.unread_count`) and `POST /api/admin/notifications/{id}/read` on the landlord, mirroring the tenant staff inbox |
| **Priority** | P2 |
| **Status** | **Resolved** in D-143. Same item shape as the staff inbox. Self-service: no permission is required. |

### BG-09: Mark All Notifications Read

| | |
|---|---|
| **Required change** | `POST …/notifications/read-all` for staff, customers, affiliates and (after BG-08) platform users. Response: `{marked: n}`. |
| **Priority** | P3 |
| **Status** | **Resolved** in D-143: `POST /api/admin/notifications/read-all` (staff, platform users), `POST /api/notifications/read-all` (customers), `POST /api/affiliate/notifications/read-all`. |

### BG-10: Platform-User Session Completeness and Per-Actor Lifetimes

| | |
|---|---|
| **Required change** | Landlord `POST /api/admin/auth/refresh` and `PATCH /api/admin/auth/password` for platform users, and per-actor token lifetimes: for example staff and platform users at 12 hours with refresh, customers and affiliates at 30 days. |
| **Reason** | Platform users hold the most privileged tokens, yet have the only 30-day non-rotating session and cannot change their password. |
| **Priority** | P2 |
| **Status** | **Resolved** in D-143. Staff and platform tokens last 12 hours (`AUTH_TOKEN_LIFETIME_STAFF`, `AUTH_TOKEN_LIFETIME_PLATFORM`, in minutes) and are renewed through `refresh`. Customers, affiliates, sellers and drivers keep `SANCTUM_EXPIRATION` (30 days), because they have no refresh route. The client shell refreshes at 75% of the lifetime (§9.3). |

### BG-11: Complete Storefront Module Flags

| | |
|---|---|
| **Required change** | Add `support`, `marketplace`, `hr_recruitment`, `sales_quotations`, `product_subscriptions` and `repair` to `StorefrontConfigService::STOREFRONT_MODULES`. |
| **Priority** | P2 |
| **Status** | **Resolved** in D-141 |

### BG-12: Upload Endpoints for Setting and Variant Images

| | |
|---|---|
| **Required change** | `POST /api/admin/settings/media` (multipart `file`, `setting`: `store_logo`, `favicon` or `seo_share_image`), returning `{media_id, url}` and storing the id in the setting. `POST /api/admin/products/{product}/variants/{variant}/image`. The landlord equivalent for the platform logo. |
| **Reason** | Onboarding's `store_details` step requires a logo, and there is no way to upload one. |
| **Priority** | P1 |
| **Status** | **Resolved** in D-142. The shipped contract is multipart `image` (not `file`) plus `setting`, and it returns 201 `{setting, media_id, url}`. `DELETE /api/admin/settings/media/{setting}` clears the slot. The variant routes are `POST` and `DELETE …/variants/{variant}/image`, and admin variants carry `image_url`. The landlord routes are `POST /api/admin/platform-settings/media` (`platform_logo`, `platform_favicon`, `seo_share_image`) and `DELETE …/{setting}`. `GET /api/platform/config` adds `{name}_url` beside each public `{name}_media_id`. Uploads count against `max_storage_mb`. |

### BG-13: Storefront Revalidation Hook

| | |
|---|---|
| **Required change** | After the backend commits changes to products, CMS, menus, storefront settings or taxonomy, it posts `{host, tags}` to `POST {storefront-internal}/bff/internal/revalidate`, signed with `REVALIDATE_SECRET` and reachable only on the private network. |
| **Priority** | P3. The baseline is TTL revalidation. |

### BG-14: POS Register Catalogue Snapshot

| | |
|---|---|
| **Required change** | `GET /api/admin/pos/registers/{register}/catalogue?since=`: prices, tax rates and stock for the register's warehouse, cursor-paginated, so offline pricing covers every product. |
| **Priority** | P3 |

### BG-15: Product View Recording Endpoint

| | |
|---|---|
| **Required change** | `POST /api/products/{product}/views` (public, throttled), recording one view. The show route stops recording when this endpoint exists. |
| **Reason** | Cached product pages do not reach the show route, so view-based sorting would undercount. |
| **Priority** | P3 |

### BG-16: CMS Draft Preview Read

| | |
|---|---|
| **Required change** | `GET /api/admin/cms/pages/{page}/preview` (and the landlord equivalent), returning the draft in the public page shape, with media URLs resolved |
| **Priority** | P3. Interim: preview renders unsaved form data. |

### BG-17: Paginator `page` Parameter in OpenAPI

| | |
|---|---|
| **Found** | While building the tenant-admin product list |
| **Capability** | A typed `page` query parameter on every length-aware list |
| **Reason** | Scramble documents the validated filters and `per_page` but not the paginator's `page`, which Laravel reads directly from the request. The frontend passes `page` through a cast. |
| **Required change** | Document `page` (integer, min 1) on every operation whose response has `meta.pagination` with `current_page`, for example in the `ApiResponseTypeExtension` document pass. |
| **Priority** | P3 |

### BG-18: Platform Setting Constraints

| | |
|---|---|
| **Found** | While building the platform-admin settings screen |
| **Capability** | Render each setting with the right control and client validation |
| **Reason** | `GET /api/admin/platform-settings/{group}` returned the type but not the allowed options or bounds. |
| **Required change** | Each entry also returns `nullable`, `options`, `min` and `max`, parsed from its validation rules. The `values` body of the group update is documented as a map. |
| **Priority** | P2 |
| **Status** | **Resolved** in the backend (`PlatformSettingsService::group`, `PlatformSettingsConstraintsTest`). |

### BG-19: Public List of Enabled Payment Gateways

| | |
|---|---|
| **Found** | While building sign-up step 4 (§24.3) |
| **Capability** | Offer only the gateways that can take a new store's first payment |
| **Reason** | `POST /api/register/{registration}/checkout` accepts `flutterwave`, `paystack` or `stripe`, but no public route says which are enabled for the plan's currency. The page offers all three and shows the backend's error for an unavailable one. |
| **Required change** | Add `available_gateways` (provider and label) to `GET /api/register/{registration}/status` while the tenant is `awaiting_payment`, from `PlatformPaymentGatewayService::availableFor`. |
| **Priority** | P2 |

### Contract Note: Public Plan Shape

The OpenAPI document types `prices` and `features` of `GET /api/plans` as strings, because `PlanService::presentPublic` builds them inline. platform-web normalises the response at runtime (`features/signup/model.ts`) instead of casting. A `@return` array shape on `presentPublic` would let the generated type match.

---

## 42. Implementation Phases

Each phase lists its dependencies, what it delivers, the tests it adds and its acceptance criteria. Phases 2 to 4 unblock everything else. From phase 5 onward, apps can progress in parallel.

| Phase | Objective | Depends on | Packages | Apps | Delivers | Tests | Acceptance |
|---|---|---|---|---|---|---|---|
| 0 | Repository hygiene | none | `ui`, config packages | all | §5.1 corrections, `affiliate-portal` scaffold, boundary rules, env schemas, CI skeleton | Lint, typecheck, boundary checks | CI green; `turbo boundaries` passes; every app boots with a validated env |
| 1 | Local edge and hosts | 0 | none | all | Caddy config, host map, `proxy.ts` host validation, health routes | Host validation unit tests | Every host of §38.6 serves its app over TLS |
| 2 | Contract | 0 | `contract` | none | Pull and generate scripts, generated paths, permission and module unions, first response overlay entries, lock file | Generate diff check in CI | `contract:generate` is reproducible; `contract:gaps` lists the overlay |
| 3 | API client and BFF | 2 | `api-client`, `bff` | all (routes) | Envelope and errors, `upstream()` with `Host`, sealing, allow-lists, CSRF, session routes, logout | Unit and BFF integration tests (§35.1) | A request with a tampered host, path or origin is refused; a session copied to another host is rejected |
| 4 | Design system and format | 0 | `ui`, `format` | none | Icon facade, composed components, tokens, `cn` fix, formatting helpers, `/ui` gallery | Component and axe tests | Every component passes axe; the gallery renders in light, dark and RTL |
| 5 | Auth per actor | 3, 4 | `bff` | all | Login, logout, reset, verify pages for all actors; staff refresh | End-to-end auth flows | §35.3 auth flows pass |
| 6 | Admin kit and access | 3, 4, 5 | `admin-kit`, `access` | platform-admin, tenant-admin | Shell, nav engine, `DataTable`, CRUD templates, `applyApiErrors`, gates, module pages, limit dialog, banners, notifications bell, `KpiGrid` | Component tests; nav visibility unit tests against the fixture manifest | A clerk and an owner see the correct navigation; module and limit states render per §11 |
| 7 | Tenant core commerce | 6 | `custom-fields` | tenant-admin | Dashboard and onboarding, catalogue, inventory, customers, orders, payments, shipping, returns, promotions, settings, users and roles, custom fields, CMS | Feature component tests; contract tests for their overlay entries | A tenant can be configured and operated end to end without optional modules |
| 8 | Bulk, imports, exports | 6, 7 | `admin-kit` | tenant-admin, platform-admin | Bulk bar and results, import flow, export centre, async hook | End-to-end import, export and bulk | §19 flows pass with partial failures |
| 9 | Storefront | 3, 4, 5 | `cms-render`, `custom-fields` | storefront | Config, themes, catalogue, product, CMS, cart, checkout, payment return, account, SEO, consent | Lighthouse CI; end-to-end checkout | Budgets met; checkout with a test gateway succeeds; a tampered host is 404 |
| 10 | Social login | 9 | `bff` | storefront, platform-web | Relay, start and callback, link and unlink, errors | End-to-end with a provider stub | New, existing and conflicting accounts behave per §9.5 |
| 11 | Platform website and signup | 3, 4 | `cms-render` | platform-web | Marketing pages, pricing, signup wizard, referral capture | End-to-end signup | Signup provisions a tenant, and its admin login works |
| 12 | Platform admin | 6, 8 | `admin-kit` | platform-admin | §25 features | Feature tests; end-to-end suspend flow | Every landlord route family in §40.2 has a screen |
| 13 | Affiliate portal | 5, 6 | `admin-kit` | affiliate-portal | §26 | End-to-end apply, login, payout details | Portal routes complete |
| 14 | Optional modules | 7, 8 | none | tenant-admin, storefront | POS, purchasing, accounting, expenses, HR, marketplace and seller portal, support, gift cards, reward points, quotations, agents, subscriptions, installments, approvals, AI assistant, projects, manufacturing, restaurant and kitchen, booking, repair, integrations, reports | Per-module feature tests; POS and support end to end | Each module's routes in §40.2 have screens gated by module state |
| 15 | Realtime | 6, 14 (support) | `realtime` | tenant-admin, platform-admin, storefront | Echo; support and helpdesk on realtime | End-to-end chat | Messages arrive under 1 s |
| 16 | Observability and hardening | all | all | all | Sentry, OTel, Web Vitals, CSP per §31.3, secret scan, headers | Security header tests; CSP report-only week, then enforced | No CSP violations in staging; alerts wired |
| 17 | Production readiness | 16 | all | all | Deploy pipelines, scaling, rollback drill, runbooks, `TRUSTED_PROXIES` set per environment | Full end-to-end in staging | Launch checklist signed |

Admin navigation from phase 6 uses the generated route manifest (§13.4, BG-07 resolved in D-142).

---

## 43. Non-Goals

- Microfrontends, module federation or runtime plugin systems.
- One frontend deployment per tenant, or per-tenant builds.
- A second state-management system beside TanStack Query, URL state and local state. Zustand only inside POS.
- Axios, or any HTTP client other than `fetch` and `openapi-fetch`.
- A second UI primitive library (Radix), or per-app copies of components.
- A second authentication system (Auth.js / NextAuth) beside Sanctum.
- Global client stores for server data.
- Frontend business logic that belongs in Laravel: pricing, totals, tax, eligibility, state transitions, entitlement.
- Hand-written module lists, permission maps or plan rules.
- Repository or service layers wrapping the API client in features.
- Imports between apps.
- Admin-app internationalisation in v1.
- The driver app, native apps, a service-worker offline POS cold start, and multi-locale storefronts in v1.
- MFA (the backend has none; it would be a backend feature first).

---

## 44. Architectural Decisions

| ADR | Decision | Chosen approach | Alternatives considered | Why chosen | Trade-offs and consequences |
|---|---|---|---|---|---|
| ADR-01 | Framework | Next.js 16 App Router, React 19.2, TypeScript strict | Remix / React Router 7, Vite SPA, Nuxt | Server Components and Cache Components for SEO and per-tenant caching; route handlers for the BFF; already installed | Major-version churn; mitigated by pins and the local docs rule |
| ADR-02 | Repository | One pnpm and Turborepo monorepo, separate from Laravel | Polyrepo; monorepo shared with Laravel; Nx | Shared packages change together; `--affected` builds; different toolchain from PHP | The contract bundle is the coupling point (§13) |
| ADR-03 | Landlord and tenant admins | Two apps sharing `admin-kit`, `access` and `ui` | One app switching by host; separate repositories | Different actors, API hosts, permissions and threat models; smaller bundles; no landlord code on tenant hosts | Some shell duplication, removed by `admin-kit` |
| ADR-04 | Affiliate portal | Its own app on `affiliates.ROOT` | Inside `platform-web`; inside `platform-admin` | `platform-web` runs analytics (origin risk); `platform-admin` must not ship to external partners | One more deployable, and a small one |
| ADR-05 | Backend for frontend | Thin Next.js route-handler BFF with sealed HttpOnly cookies | Browser-to-API with bearer tokens in storage; Auth.js | Tokens never in JavaScript; server rendering of authenticated pages; host-based tenant identity for the admin origin | One extra private hop; owned session code |
| ADR-06 | Tenant resolution | From the request `Host` only; the BFF sends `Host: {apiHost}` to Laravel | `X-Forwarded-Host`, headers or parameters | Matches Laravel's resolver exactly (§3.1); nothing spoofable by the client | Needs an HTTP client that can set `Host` (undici) |
| ADR-07 | Host layout | `platform.ROOT`, `affiliates.ROOT`, `{slug}.admin.ROOT`, `{slug}.ROOT` and custom domains, `cdn.ROOT`, Reverb on `ROOT/app` | Admin under `{store}/admin`; `admin.ROOT/{slug}` | Origin isolation from tenant third-party scripts; host-only cookies per tenant; uses already-reserved labels | The backend builds admin links from `TENANT_ADMIN_URL` (D-141) |
| ADR-08 | Staff session lifetime | Token lifetime with proactive refresh and single-tab refresh through Web Locks | Refresh on every request; long-lived without refresh | Backend refresh revokes the old token; one refresher avoids races | Customers and platform users re-login at expiry until BG-10 |
| ADR-09 | Customer social login | Full-page redirect; platform-wide relay on `platform-web`; sealed per-flow cookie; relay validates the target is a live tenant store | Popup; per-tenant redirect URIs; the relay trusting the state prefix | One redirect URI per provider (backend design); works in in-app browsers; prevents open redirect and code exfiltration | One extra redirect hop |
| ADR-10 | API client | `openapi-fetch` over native `fetch`, with envelope and error middleware and typed helpers | Axios; a generated SDK; hand-written fetch wrappers | Zero runtime weight; typed paths and bodies; one place to unwrap the envelope | A small response overlay remains for the operations Scramble cannot type |
| ADR-11 | Type generation | Generated paths, request and response types (BG-06, D-142), plus a small contract-tested overlay for the operations still typed as `string` | Hand-maintained overlay for everything; fully manual | Uses what OpenAPI types; tests keep the overlay honest | About 35 overlay entries to maintain |
| ADR-12 | Server state | TanStack Query (client) and Next Cache Components (server, public data only) | SWR; RTK Query; a global store | Mature invalidation, SSR hydration, infinite queries | Two caches with strictly separated responsibilities |
| ADR-13 | URL state | nuqs, with API query names as URL names | Hand-parsed `searchParams`; in-memory filters | Shareable, back-button-safe lists; no renaming layer | URL names follow the API naming |
| ADR-14 | Forms | React Hook Form + Zod for shape; Laravel 422 authoritative, mapped by `applyApiErrors` | TanStack Form; mirrored backend rules | Fast large forms; no rule drift | Some errors appear only after submit |
| ADR-15 | Permissions and module access | Interpret backend snapshot and manifest; gates by route name; no frontend rules | A frontend permission or plan model | One source of truth; gating is UX only | The contract bundle must be re-pulled when routes change (BG-07, D-142) |
| ADR-16 | UI system | shadcn `base-nova` on Base UI in `packages/ui`, HugeIcons behind a facade, Tailwind v4 tokens | Radix-based shadcn; MUI; per-app components | Already installed; accessible primitives; owned code | Maintained copies of components |
| ADR-17 | Bulk actions | A synchronous bulk bar with per-item results; per-page selection only | Async bulk jobs; cross-page selection | Matches the backend's 100-item synchronous contract | Large changes use imports instead |
| ADR-18 | Imports | Upload, then a queued status page with polling and an error-row table; no preview or mapping | A client-side parse and preview; column mapping | The backend validates per row and provides no preview endpoint; templates fix the headings | Users fix files and re-upload |
| ADR-19 | Exports | An export centre with queued status, a download link through the BFF, and 7-day expiry | Synchronous downloads | Matches the queued backend and signed or streamed files | Users wait for large exports |
| ADR-20 | Async operations | One polling hook with backoff, plus notification-inbox completion | Realtime for every job; per-feature polling loops | The backend broadcasts no job events | Polling load, bounded by backoff and visibility |
| ADR-21 | Realtime | Echo and Reverb only for support and helpdesk conversations, with polling fallback | Realtime notifications and dashboards | Only these events are broadcast | Notifications arrive within 60 seconds |
| ADR-22 | Storefront rendering | Server Components with per-tenant remote cache, client islands for personal data | A client SPA; full SSR without caching | SEO and TTFB at scale across many tenants | Changes appear after TTLs until BG-13 |
| ADR-23 | Theming | Theme modules per backend identifier, validated CSS-variable colours, computed contrast, self-hosted fonts | Arbitrary tenant CSS; runtime font URLs | Safe, fast, consistent, and unlimited tenants on one build | New themes need a coordinated release |
| ADR-24 | Deployment | Self-hosted standalone containers per app behind one edge with on-demand TLS; independent deploys | Vercel; one combined deployment | Unlimited custom domains with on-demand TLS; co-location with Laravel and Reverb | Operating the edge and Redis cache |
| ADR-25 | Testing | Vitest unit and component tests with axe; BFF integration tests; contract tests against a seeded backend; Playwright for critical flows | End-to-end for every endpoint | Fast feedback; the contract drift catch is where the risk is | Maintaining the seeded backend image |

---

## 45. Consistency Audit

This audit was run against every input in §2.1, and each finding was fixed in this document.

| Check | Result |
|---|---|
| Envelope, pagination and error codes match the code (`APIResponse`, `ExceptionRenderer`) | Fixed from the draft (§3.3, §12, §30) |
| Every frontend route that the backend links to exists (`FrontendUrl`, `config/cms.php`) | Verified in §3.8, §24, §27.2, §29.2 |
| Every host label is either reserved by the backend or non-tenant by construction | Verified (§6.2) |
| No endpoint is used that the route list lacks | Verified against the 1,238-route list. Missing capabilities are gaps BG-01 to BG-16. |
| Social login uses only the implemented endpoints, response fields and error codes | Verified (§9.5) |
| Bulk, import and export contracts match D-133 to D-135 and the controllers | Verified (§19) |
| Module keys, limit keys and storefront flags match `config/modules.php`, `config/limits.php` and `StorefrontConfigService` | Verified (§3.6) |
| Realtime channels and auth routes match `routes/channels.php` and the events | Verified (§3.7, §21.2) |
| Dashboard sections match `config/dashboards.php`; `/metrics` resources match the routes | Verified (§22) |
| Tenant resolution matches `HostTenantResolver` (`Host`, no proxies) | Verified (§7) |
| The frontend repository's versions and packages are reflected, and nothing contradicts it without a stated migration | Verified (§4, §5.1) |
| Draft sections 35 to 50 existed only as references | Written (Parts V and VI) |
| Contradictions between draft files (for example `/checkout/return` against the backend callback, `X-Forwarded-Host` against `Host`, `console.ROOT`, missing affiliates) | Resolved (§2.2) |
| Tenant isolation risks (shared cache, host spoofing, session replay, OAuth relay) | Each has a control (§7.5, §14.4, §24.5, §31.1) |
| Async operation handling for every queued backend operation | §19.4 table |
| Accessibility, performance, SEO, observability, testing and deployment strategies | §29.9, §32, §33, §35, §36, §38 |
| Secrets never in client bundles; OAuth secrets never in the frontend | §31.4, §37 |

Known unavoidable differences between backend and frontend are exactly the gaps in §41. Each has an interim behaviour defined in the section that depends on it.
