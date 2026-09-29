# Local setup: create your first tenant and use the API in Postman

This is the checklist for running the platform locally on Herd (Windows), creating a store (tenant), and calling both APIs from Postman.

Everything marked **✅ done** was checked against your machine on 27 Sep 2026. Everything marked **➡ you** is a step only you can do.

---

## 1. What is already in place ✅

| Item | State |
|---|---|
| `.env` | Every key in `.env.example` is present, and the local values are consistent. Nothing needs to change to create a tenant (see §2). |
| Landlord migrations | All run (0 pending). |
| Landlord seeders | Run again today (`php artisan db:seed`). They are insert-only and never overwrite your edits. Present: 250 countries and currencies, 55 platform settings, plans Basic, Standard and Premium, notification templates, draft legal documents, and the website CMS. |
| Platform admin | `muibi.azeezabolade@gmail.com`, super-admin, password set. |
| MySQL user | Can create databases, which tenant provisioning needs. |
| Database servers | None registered. That is correct for one machine: tenants use the `DB_*` / `TENANT_DB_*` connection. |
| Tenant defaults | Applied automatically when a tenant is provisioned: roles, permissions, notification templates, CMS, catalogue, returns, documents, and the chart of accounts. |
| API docs | Exported to `docs/api/landlord.openapi.json` and `docs/api/tenant.openapi.json` (§7). |

### What still blocks tenant creation

1. **Legal documents are drafts.** Sign-up returns `503 registration_unavailable` until the *Terms of Service* and *Privacy Policy* are **published** (§4).
2. **A queue worker must run.** Both the verification email and the database provisioning are queued jobs (§3).
3. **The store's subdomain must resolve.** Herd on Windows does not do wildcard DNS, so each store needs one hosts-file line (§3.3).

---

## 2. `.env`: what matters for tenant creation

You don't need to change anything. For reference:

| Key | Your value | Why it matters |
|---|---|---|
| `APP_URL` | `http://tenant-ecommerce-api.test` | The landlord API base: `http://tenant-ecommerce-api.test/api` |
| `PLATFORM_ROOT_DOMAIN` | `tenant-ecommerce-api.test` | Stores get `{slug}.tenant-ecommerce-api.test` |
| `CENTRAL_DOMAINS` | `localhost,127.0.0.1` | These hosts, plus the root domain, serve the landlord API |
| `TENANCY_DB_PREFIX` | `tea_tenant_` | Each store's database is `tea_tenant_{tenant-id}` |
| `DB_*` | root on 127.0.0.1 | Also used for tenant databases while `TENANT_DB_*` is empty |
| `QUEUE_CONNECTION` | `database` | Jobs wait in the `jobs` table until a worker runs them (§3) |
| `MAIL_MAILER` | `log` | Emails, including the **6-digit verification code**, are written to `storage/logs/laravel.log` |
| `PAYMENTS_LIVE_ALLOWED` | `false` | Keeps billing in test mode. That is correct locally. |

Optional, only when you need them:

- **Real emails:** set `MAIL_MAILER=smtp` and the `MAIL_*` values, for example a Mailtrap inbox.
- **SMS, WhatsApp, push:** only for those channels. Registration does not need them.

---

## 3. Start the background pieces ➡ you

### 3.1 Queue worker (required)

Keep this running in its own terminal:

```powershell
cd C:\Users\amuibi\Herd\tenant-ecommerce-api
php artisan queue:work --queue=tenant-critical,landlord-default,tenant-default,tenant-bulk --tries=3
```

Without it, sign-up stops at "check your email" and provisioning never starts.

### 3.2 Scheduler (optional locally)

This runs daily maintenance, renewals and similar jobs. Registration does not need it.

```powershell
php artisan schedule:work
```

### 3.3 Make the store's subdomain resolve (required, once per store)

Herd adds one hosts-file line per site. It does not add wildcard subdomains.

I checked that Herd already routes `anything.tenant-ecommerce-api.test` to this app. Only the name lookup is missing.

After you know the store's slug (§5, step 4), open **PowerShell as Administrator** and run:

```powershell
Add-Content -Path C:\Windows\System32\drivers\etc\hosts -Value "127.0.0.1 mystore.tenant-ecommerce-api.test"
ipconfig /flushdns
```

Replace `mystore` with your store's slug.

If you'd rather not edit hosts, Postman can send the request to `http://127.0.0.1/api/...` with the header `Host: mystore.tenant-ecommerce-api.test`.

---

## 4. One-time platform configuration ➡ you (Postman, landlord API)

Base URL: `http://tenant-ecommerce-api.test/api`. All `/admin` requests need `Authorization: Bearer {token}`.

### 4.1 Log in as the platform admin

```http
POST /admin/auth/login
{ "email": "muibi.azeezabolade@gmail.com", "password": "YOUR_PASSWORD", "device_name": "postman" }
```

Copy `data.token`.

If you don't remember the password, reset it:

1. Send `POST /admin/auth/password/forgot` with `{ "email": "…" }`.
2. Open `storage/logs/laravel.log` and copy the `token=` value from the reset link.
3. Send `POST /admin/auth/password/reset` with `{ "token": "…", "email": "…", "password": "…", "password_confirmation": "…" }`.

### 4.2 Publish the Terms of Service and Privacy Policy (required)

```http
GET /admin/legal-documents
```

Note the `id` of `terms_of_service` and of `privacy_policy`. With the seeded data these are **1** and **2**.

Replace the placeholder text, which is still editable while in draft:

```http
PATCH /admin/legal-documents/1
{ "version": "1.0", "title": "Terms of Service", "body": "Your real terms…" }

PATCH /admin/legal-documents/2
{ "version": "1.0", "title": "Privacy Policy", "body": "Your real privacy policy…" }
```

Then publish both. Published documents can't be edited; later changes go in a new version.

```http
POST /admin/legal-documents/1/publish
POST /admin/legal-documents/2/publish
```

Check the result with `GET /legal-documents/current` (public). It must list both documents with their ids. Registration sends these ids back.

### 4.3 Platform settings (defaults already allow sign-up)

These settings are already correct. Change them only if you want different behaviour:

| Group / key | Default | Effect |
|---|---|---|
| `tenant_onboarding.tenant_registration_enabled` | `true` | Self-service sign-up is open |
| `trials.trials_enabled` | `true` | Trials are allowed |
| `trials.default_trial_days` | `7` | Used when a plan price has no trial set |
| `tenant_onboarding.registration_verification_hours` | `24` | How long the email code is valid |

To change one:

```http
PATCH /admin/platform-settings/{group}
{ "values": { "key": value }, "reason": "why" }
```

### 4.4 Which plan to pick

| Plan price (id) | Trial | Result of sign-up |
|---|---|---|
| Basic monthly (**1**) / yearly (**2**) | 7-day default trial | **Provisions immediately. No payment needed.** Use this locally. |
| Standard (3, 4) and Premium (5, 6) | trial 0 | Waits for the first payment (`awaiting_payment`). Needs a billing gateway (§4.5). |

The Standard plan includes `accounting`. To try accounting on a Basic trial store, give that tenant the feature from the landlord admin with `POST /admin/tenants/{tenant}/features`.

### 4.5 Billing gateway (only for paid plans)

Set up at least one test gateway before a store can pay or upgrade. The full walkthrough is in **§8**.

---

## 5. Create a tenant (self-service sign-up) ➡ you

All of these are landlord public routes: no token needed.

1. **Collect the ids:**
   - `GET /lookups/countries`: pick your country's `value`. Nigeria is `160`.
   - `GET /plans`: the Basic monthly price id is `1`.
   - `GET /legal-documents/current`: the ids you published (1 and 2).

2. **Register:**

   ```http
   POST /register
   {
     "business_name": "My Store",
     "owner_name": "Your Name",
     "email": "owner@example.com",
     "password": "Str0ngPassw0rd!",
     "password_confirmation": "Str0ngPassw0rd!",
     "country_id": 160,
     "plan_price_id": 1,
     "accepted_legal_document_ids": [1, 2]
   }
   ```

   The response is `202` with `data.registration_id`. `160` is Nigeria; use your own `country_id` from step 1.

3. **Get the code:** with the queue worker running, open `storage/logs/laravel.log` and find the *verify your email* message. It contains a 6-digit code.

4. **Verify:**

   ```http
   POST /register/verify
   { "registration_id": "…", "code": "123456" }
   ```

   The response includes `tenant_id`, `domain` (for example `my-store.tenant-ecommerce-api.test`) and `tenant_status: provisioning`.

   → Add the hosts line for that domain now (§3.3).

5. **Wait for provisioning:** the worker creates the database, runs the tenant migrations, seeds the defaults and creates the owner. Poll:

   ```http
   GET /register/{registration_id}/status
   ```

   Wait until `tenant_status` is `active`. This usually takes 10–60 seconds.

   Platform admins can instead list tenants with `GET /admin/tenants` (bearer token).

---

## 6. Use the store's (tenant) API ➡ you

Base URL: `http://{slug}.tenant-ecommerce-api.test/api`, for example `http://my-store.tenant-ecommerce-api.test/api`.

| Who | How to authenticate |
|---|---|
| Staff (you, the owner) | `POST /admin/auth/login` with the email and password from registration. Then `Authorization: Bearer {token}` on every `/admin/...` request. |
| Customer | `POST /auth/register` or `POST /auth/login` on the storefront. The bearer token then works on storefront routes. |
| Guest shopper | No token. The first `POST /cart/items` returns `guest_token`. Send it as the `X-Guest-Token` header on cart, checkout and order requests. |

Headers worth knowing:

- `Accept: application/json` on everything. The API forces JSON anyway.
- `Idempotency-Key: {uuid}` is **required** on money routes: `POST /orders`, `POST /orders/{order}/pay`, refunds, order payments and manual journal entries. Use `{{$guid}}` in Postman.

Good first calls:

- `GET /admin/auth/me`
- `GET /admin/dashboard`
- `GET /admin/modules`
- `POST /admin/products`
- `GET /storefront/config`

---

## 7. Postman: import the two APIs

1. Postman → **Import** → **Files**, and choose both:
   - `docs/api/landlord.openapi.json` → *Landlord API*
   - `docs/api/tenant.openapi.json` → *Tenant API*

   Choose **"OpenAPI 3.1 with a Postman Collection"**.

2. In each collection's **Variables**, set `baseUrl`:
   - Landlord: `http://tenant-ecommerce-api.test/api`
   - Tenant: `http://my-store.tenant-ecommerce-api.test/api` (your store's domain)

3. In each collection's **Authorization** tab, choose **Bearer Token** with `{{token}}`, and set the `token` variable after logging in.

   Public requests (register, lookups, storefront) work with or without it.

Notes on the generated docs:

- Every route is included: 225 landlord operations and 499 tenant operations.
- Request bodies come from each endpoint's validation rules, including rules inside services.
- Endpoints that show no body are actions that take none: activate, publish, logout and similar.
- A few settings-style endpoints validate against runtime registries, so they have no fixed field list:
  - `PATCH /admin/settings` and `PATCH /admin/storefront-settings` take `{ "values": { "key": value } }`;
  - `POST /admin/custom-fields`.

**Browse the docs in a browser** (local only, while `APP_DEBUG=true`):

- Landlord: `http://tenant-ecommerce-api.test/docs/api`
- Tenant: `http://tenant-ecommerce-api.test/docs/api/tenant`

**Regenerate after API changes:**

```powershell
php artisan api-docs:export
```

This rewrites both JSON files. In Postman, re-import them, or link the files and pull changes. The first run creates an empty schema-only database, `tea_tenant_docs_schema`. It holds the tenant table structure the docs generator reads, never any data.

---

## 8. Billing: test gateways and upgrading a plan ➡ you

This section takes a store from its free trial to a paid plan, then to a bigger plan, using **test keys** from Paystack, Flutterwave and Stripe. No real money moves.

### 8.0 How it works (read once)

```
Platform admin (landlord API)           Store owner (tenant API)                 Provider
──────────────────────────────          ─────────────────────────────            ──────────────
1. Save test keys, check, enable  ──►   2. See plans + which providers can pay
                                        3. Start checkout ──► checkout_url  ──►  4. Pay with a test card
                                                                                  5. Webhook ──► landlord API
                                        6. Subscription becomes active   ◄──     (queue worker applies it)
                                        7. Later: upgrade (swap-plan)    ──►     card saved in step 4 is charged
```

The facts that matter:

- **Your platform's keys are used.** Stores pay the platform, so the keys go in the landlord admin, not in the store.
- **Currencies.** Plans are priced in USD, NGN, GHS, KES, ZAR, GBP and EUR (§9.5). Your store's trial subscription (Softmaxtech Online Store) started on the Basic USD price. A gateway is offered only if its `supported_currencies` includes the price's currency.
- **How the currency is picked:** a store that has **never paid** is priced in its own currency (NGN for your store) when the plan has a price in it, else USD. The plan list and the checkout always agree. Once a store **has paid**, it stays in the currency it paid in, because its saved card and prorations are in that currency.
- **NGN prices are seeded** (§9.5), so a Nigerian store that hasn't paid yet sees and pays NGN, which suits Paystack. To change a price, add a new one with `POST /admin/plans/{plan}/prices` and `{ "currency_code": "NGN", "billing_interval": "monthly", "amount": "17000.00" }`; it replaces the active one. Prices are never edited in place.
- **The payment is confirmed by the webhook, not by the browser redirect.** After paying, the provider calls `…/api/webhooks/{provider}/test` on this API. Locally that needs a tunnel (§8.3), or the manual fallback (§8.6).
- **The queue worker must run.** Webhooks are processed on the `landlord-default` queue (§3.1).
- **Billing mode is `test` and must stay `test` locally.** `PAYMENTS_LIVE_ALLOWED=false` refuses live keys. That's correct.

State checked on 27 Sep 2026: billing mode `test`, **no gateways configured yet**, proration mode `next_renewal`, and your store is `trialing` on Basic monthly until 4 Oct 2026 with no saved card.

### 8.1 Get the test keys from each dashboard ➡ you

Switch each dashboard to **Test mode** first.

| Provider | Where | What to copy | Webhook setting (after §8.3) |
|---|---|---|---|
| **Paystack** | Settings → **API Keys & Webhooks** | Test **Public key** `pk_test_…` and test **Secret key** `sk_test_…` | Paste the **Test Webhook URL**. There is no separate webhook secret: Paystack signs with your secret key. |
| **Flutterwave** | Settings → **API Keys** (Test mode) | **Public key** `FLWPUBK_TEST-…` and **Secret key** `FLWSECK_TEST-…`. The encryption key isn't needed. | Settings → **Webhooks**. Paste the URL and **type your own "Secret hash"**, any long random string. Save that same string as `webhook_secret`. Tick *receive webhooks for failed payments*. |
| **Stripe** | Developers → **API keys** (Test mode) | **Publishable key** `pk_test_…` and **Secret key** `sk_test_…` | Developers → **Webhooks** → *Add endpoint*. Paste the URL and select the events below. Then copy the **Signing secret** `whsec_…`: it is your `webhook_secret`. |

Stripe events to select: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refund.updated`, `refund.updated`, `charge.dispute.created`, `charge.dispute.closed`.

> **Paystack and USD:** a Paystack test account charges NGN by default. USD works only if USD is enabled on your Paystack business. If Paystack returns "currency not supported", test with Stripe or Flutterwave, which both accept USD in test mode.

Never paste keys into the repo or `.env`. They live only in the database, encrypted, and the API never shows a saved secret again.

### 8.2 Save, check and enable each gateway (landlord API, admin token)

Base URL `http://tenant-ecommerce-api.test/api`, with `Authorization: Bearer {platform admin token}` (§4.1).

**Paystack**

```http
PUT /admin/payment-gateways/paystack/test
{
  "public_key": "pk_test_xxx",
  "secret_key": "sk_test_xxx",
  "supported_currencies": ["USD", "NGN"]
}
```

**Flutterwave**

```http
PUT /admin/payment-gateways/flutterwave/test
{
  "public_key": "FLWPUBK_TEST-xxx",
  "secret_key": "FLWSECK_TEST-xxx",
  "webhook_secret": "the-secret-hash-you-typed-in-flutterwave",
  "supported_currencies": ["USD", "NGN"]
}
```

**Stripe**

```http
PUT /admin/payment-gateways/stripe/test
{
  "public_key": "pk_test_xxx",
  "secret_key": "sk_test_xxx",
  "webhook_secret": "whsec_xxx",
  "supported_currencies": ["USD"]
}
```

Saving calls the provider to check the keys. A wrong key returns `422 gateway_credentials_invalid`. A live key in the test slot returns `422 payment_mode_mismatch`.

If you don't have the webhook secret yet (Stripe, Flutterwave), save without it now. Send the same `PUT` later with just `{ "webhook_secret": "…" }`: omitted fields keep their saved values.

Then, for each provider:

```http
POST /admin/payment-gateways/{provider}/test/test          → "valid": true
POST /admin/payment-gateways/{provider}/test/enable
POST /admin/payment-gateways/{provider}/test/set-default   (optional: listed first to stores)
```

Check everything:

```http
GET /admin/payment-gateways
```

Each row shows `is_enabled`, `credentials_verified_at`, `has_webhook_secret`, `last_webhook_at` and `webhook_url`.

Optional fields on the `PUT`: `supported_country_ids` (limit a provider to some countries, for example `[160]` for Nigeria) and `sort_order`.

Only needed if someone switched it: the billing mode must be `test`.

```http
POST /admin/payment-gateways/mode   { "mode": "test", "confirm": true, "reason": "local testing" }
```

### 8.3 Let the providers reach your machine (webhooks) ➡ you

`tenant-ecommerce-api.test` only exists on your PC, so the providers can't call it. Pick one option.

**Option A: ngrok (works for all three).** Install ngrok, run `ngrok config add-authtoken …` once, then keep this running:

```powershell
ngrok http 80 --host-header=tenant-ecommerce-api.test
```

`--host-header` makes Herd treat the requests as your landlord domain, so the webhook route matches. You don't need to change `CENTRAL_DOMAINS`.

Your webhook URLs are then:

```
https://{your-id}.ngrok-free.app/api/webhooks/paystack/test
https://{your-id}.ngrok-free.app/api/webhooks/flutterwave/test
https://{your-id}.ngrok-free.app/api/webhooks/stripe/test
```

Paste each into its dashboard (§8.1). The free ngrok URL changes every restart unless you claim the free static domain in the ngrok dashboard. Claim it, or you'll have to update three dashboards each time.

**Option B: Stripe CLI (Stripe only, no tunnel).**

```powershell
stripe login
stripe listen --forward-to http://tenant-ecommerce-api.test/api/webhooks/stripe/test
```

It prints a `whsec_…`. Save it as Stripe's `webhook_secret` (§8.2). That secret differs from the dashboard endpoint's secret, so use one approach or the other.

**Option C: no webhooks.** Skip this step and confirm each payment by hand after paying (§8.6).

### 8.4 What the store owner sees (tenant API, owner token)

Base URL `http://softmaxtech-online-store.tenant-ecommerce-api.test/api`, with `Authorization: Bearer {owner token}` from `POST /admin/auth/login` (§6).

```http
GET /admin/billing/subscription
```

This returns the current plan, `status` (`trialing`, `active`, `past_due`…), `renews_at` and the next cycle total.

```http
GET /admin/billing/plans
```

This lists each public plan with its prices. Every price has a `gateways` list, for example `["stripe","flutterwave","paystack"]`: the providers able to charge it. **An empty list means no enabled test gateway supports USD. Fix §8.2.**

Plan and price ids (seeded):

| Plan | Monthly price id | Yearly price id |
|---|---|---|
| Basic (plan 1) | 1 ($20) | 2 ($200) |
| Standard (plan 2) | 3 ($39) | 4 ($380) |
| Premium (plan 3) | 5 ($59) | 6 ($600) |

The billing routes take the **plan id** plus `billing_interval`, not the price id.

### 8.5 Upgrade: pick the right path

| Store's current state | Use | What happens |
|---|---|---|
| **Trialing**, never paid (your store now) | **Path A**: `POST /admin/billing/subscription` | Checkout for the chosen plan. Paying ends the trial, activates the plan and **saves the card** for renewals and upgrades. |
| `incomplete`, `past_due` or cancelled | **Path A** | Same: pays and (re)activates. |
| **Active** (has paid before) | **Path B**: `POST /admin/billing/subscription/swap-plan` | Changes plan. How it's charged depends on `proration_mode` (below). |

> ⚠ **While trialing, don't use swap-plan to upgrade.** On a trial, swap-plan switches the plan without payment. Standard and Premium have no trial, so the trial ends at once. The next renewal then finds no saved card, and the store goes `past_due`. Use Path A while trialing.

#### Path A: pay for a plan (trial → paid)

Optional first, to check a coupon:

```http
POST /admin/billing/coupon-preview
{ "coupon_code": "WELCOME10", "plan_id": 2, "billing_interval": "monthly" }
```

Start the checkout. The `Idempotency-Key` header is **required**; use `{{$guid}}` in Postman.

```http
POST /admin/billing/subscription
Idempotency-Key: {{$guid}}
{ "plan_id": 2, "billing_interval": "monthly", "gateway": "stripe" }
```

`gateway` is `paystack`, `flutterwave` or `stripe`; it must be in that price's `gateways` list. Add `"coupon_code": "…"` if you have one.

The response is `201` with `checkout_url`, `reference` (for example `SUB-…`) and `status: "pending"`.

1. **Open `checkout_url` in a browser and pay with a test card:**

   | Provider | Card | Other details |
   |---|---|---|
   | Stripe | `4242 4242 4242 4242` | any future expiry, any CVC |
   | Paystack | `4084 0840 8408 4081` | expiry any future date, CVV `408`, PIN `0000`, OTP `123456` |
   | Flutterwave | `5531 8866 5214 2950` | expiry `09/32`, CVV `564`, PIN `3310`, OTP `12345` |

2. **The provider redirects the browser** to `https://softmaxtech-online-store.tenant-ecommerce-api.test/admin/billing/callback?reference=…`. That is the store-admin *frontend* page. Until the frontend exists, the browser shows an error. **That's expected and harmless:** the payment is confirmed by the webhook, not by this page.

3. **The webhook arrives** (§8.3). The worker marks the charge successful, sets the subscription `active` on the new plan, saves the card token, sets `renews_at` a month or year ahead, and emails *payment received*.

4. **Check:**

   ```http
   GET /admin/billing/subscription    → status "active", plan Standard
   GET /admin/billing/transactions    → the charge, status "successful"
   ```

   Platform side: `GET /admin/payment-transactions` and `GET /admin/subscriptions` (landlord).

A coupon that covers the whole amount skips the provider: the response already says `status: "successful"`, with no `checkout_url`.

#### Path B: change plan on an active subscription

1. **Preview the impact:**

   ```http
   GET /admin/billing/subscription/plan-change-preview?plan_id=3&billing_interval=monthly
   ```

   This returns `direction` (`upgrade`, `downgrade` or `interval_change`), `effective_at`, modules unlocked or locked, and any limits the new plan would exceed.

2. **Swap:**

   ```http
   POST /admin/billing/subscription/swap-plan
   Idempotency-Key: {{$guid}}
   { "plan_id": 3, "billing_interval": "monthly", "confirm_impact": true }
   ```

   `confirm_impact` is only required when the preview shows locked modules or exceeded limits. Without it, you get `422 plan_change_requires_confirmation` with the details.

What happens depends on the platform setting `billing.proration_mode`:

| `proration_mode` | Upgrade | Downgrade / interval change |
|---|---|---|
| `next_renewal` (**default**) | Scheduled. The new plan starts at `renews_at`, and the renewal charges the new price. Nothing is charged now. | Scheduled at renewal |
| `immediate` | Applied **now**. The saved card is charged the price difference for the rest of the cycle, with no browser step. | Scheduled at renewal |

To test an immediate upgrade charge locally, switch the mode (landlord admin):

```http
PATCH /admin/platform-settings/billing
{ "values": { "proration_mode": "immediate" }, "reason": "test proration" }
```

A scheduled change shows as `scheduled_plan_id` on `GET /admin/billing/subscription`.

Other tenant billing routes: `POST /admin/billing/subscription/cancel` (cancels at the end of the paid period) and `GET /admin/billing/transactions`.

### 8.6 Confirm a payment without webhooks (fallback)

If no webhook can reach you (Option C, or ngrok was off), the platform confirms pending charges itself. The daily billing job asks the provider for the status of every charge that has been pending for more than 15 minutes.

Run the job now instead of waiting for 01:00 UTC. Wait 15 minutes after paying, then:

```powershell
cd C:\Users\amuibi\Herd\tenant-ecommerce-api
php artisan tinker --execute="dispatch_sync(new App\Modules\Billing\Jobs\ProcessSubscriptionRenewal)"
```

Then check `GET /admin/billing/subscription` again.

A card is saved only when the provider returns a reusable token (on the webhook or on this check). Without a saved card, Path B upgrades and renewals can't charge.

### 8.7 Checklist, in order

1. ☐ Queue worker running (§3.1).
2. ☐ Test keys copied from each dashboard (§8.1).
3. ☐ `PUT` keys, then `test`, then `enable` for each provider, with `supported_currencies` including `USD` (§8.2).
4. ☐ Tunnel running and webhook URL (plus Flutterwave secret hash or Stripe signing secret) set in each dashboard (§8.3).
5. ☐ `GET /admin/billing/plans` on the store shows your providers under each price (§8.4).
6. ☐ Path A: pay for Standard with a test card, and the subscription becomes `active` (§8.5).
7. ☐ Path B: swap to Premium, either scheduled (`next_renewal`) or charged now (`immediate`) (§8.5).

When you go live later, the steps are the same with the **live** slot (`/admin/payment-gateways/{provider}/live`), live keys and live webhook URLs on a real public domain. Set `PAYMENTS_LIVE_ALLOWED=true` on the production server only, then switch mode with `POST /admin/payment-gateways/mode`. Existing subscriptions keep the mode they were created in.

---

## 9. Files and storage (local now, S3 later)

### 9.1 How it works

- **The platform provides storage for every store.** Stores never enter storage keys. Each store's files live under its own `tenants/{store-id}/` prefix, which the tenancy package adds automatically. Platform files (website images, the platform logo) have no prefix.
- There are two storage areas, called *disks*:

  | Disk | Holds | How files are served |
  |---|---|---|
  | `media-public` | Product, variant, category and brand images, logos, banners, CMS images | A direct URL (a CDN URL in production) |
  | `media-private` | Receipts, exports, digital downloads, return photos, proof of delivery, custom-field files, support attachments | Only after an authorisation check: streamed locally, or a 5-minute signed link on S3 |

- **`MEDIA_DRIVER` in `.env` decides where they live.** `local` (default) uses folders on this PC; `s3` uses any S3-compatible service. The code is the same either way.
- **Plan storage limits still apply.** `max_storage_mb` is 2 GB on Basic, 20 GB on Standard and 100 GB on Premium. It's checked on every upload, whatever the driver. To raise one store's limit, use a tenant limit override in the landlord admin.

### 9.2 Local (what you use now) ✅

Nothing to set. `MEDIA_DRIVER` defaults to `local`:

- Public files go to `storage/app/public/media/tenants/{store-id}/…` and are served at `http://tenant-ecommerce-api.test/storage/media/tenants/{store-id}/…`. The `public/storage` link already exists; if it's missing, run `php artisan storage:link`.
- Private files go to `storage/app/private/media/tenants/{store-id}/…`, which is never web-served.

Files uploaded **before this change** keep their old location and still work. Only new uploads use the media disks.

### 9.3 Moving to S3-compatible storage (production)

Recommended: **Cloudflare R2**. It's S3-compatible and charges nothing for downloads (egress), which matters for image-heavy stores. AWS S3, DigitalOcean Spaces and Backblaze B2 work the same way.

1. Create **one bucket**, for example `platform-media`.
2. Make **only the `public/` prefix** publicly readable, through the bucket policy or a CDN or custom domain in front of it. Leave `private/` private. Don't enable per-object ACLs: modern buckets disable them, and the app doesn't use them.
3. Create an API token or access key with read and write access to that bucket.
4. Set `.env` on the server:

   ```dotenv
   MEDIA_DRIVER=s3
   AWS_ACCESS_KEY_ID=…
   AWS_SECRET_ACCESS_KEY=…
   AWS_BUCKET=platform-media
   AWS_DEFAULT_REGION=auto                                      # AWS: your region, e.g. eu-west-2
   AWS_ENDPOINT=https://{account-id}.r2.cloudflarestorage.com   # empty for AWS S3
   MEDIA_PUBLIC_URL=https://media.yourplatform.com              # the public/CDN URL of the bucket
   ```

5. Run `php artisan config:clear` and restart the queue workers.

The bucket then looks like this:

```
platform-media/
  public/{media-id}/…                     platform website images and logo
  public/tenants/{store-id}/{media-id}/…  each store's images   → https://media.yourplatform.com/public/tenants/…
  private/tenants/{store-id}/{media-id}/… each store's private files (signed links only)
```

**Keys live in `.env` only, never in a settings screen.** They are server secrets, and switching storage at runtime would strand the files already stored.

**Test S3 locally (optional):** run MinIO in Docker, then set `AWS_ENDPOINT=http://127.0.0.1:9000`, `AWS_USE_PATH_STYLE_ENDPOINT=true` and `MEDIA_PUBLIC_URL=http://127.0.0.1:9000/{bucket}`.

**Exports and purges include the media disks.** A store's data export and its final backup before a purge now include both media disks, locally or on S3. A purge deletes the store's prefixes on both disks.

### 9.4 Low-stock threshold per product ✅

- Each **product** and **variant** can have its own `low_stock_threshold`. The value used is the variant's, then the product's, then the store setting `low_stock_threshold` (default 5).
- Empty (`null`) means "use the next level up". `0` means "never report this item as low" (out of stock is still reported).
- Set it with `PATCH /admin/products/{id}` `{ "low_stock_threshold": 20 }`, or on a variant with the variant update request.
- The low-stock list (`GET /admin/inventory/low-stock`), the dashboard low-stock count and table, and the `inventory.low_stock` notification all use it. Each low-stock row shows the threshold that applied.

### 9.5 After pulling these changes ➡ you (once)

```powershell
cd C:\Users\amuibi\Herd\tenant-ecommerce-api
composer install                                              # adds the S3 driver package
php artisan tenants:migrate                                   # adds low_stock_threshold to every store
php artisan db:seed --class="Database\Seeders\Landlord\PlanCatalogueSeeder"   # adds the new currency prices
php artisan config:clear
```

Then restart the queue worker.

The plan seeder only **adds** missing prices. Any price you've edited or deactivated is left alone.

Seeded prices (monthly / yearly):

| | Basic | Standard | Premium |
|---|---|---|---|
| USD | 20 / 200 | 39 / 380 | 59 / 600 |
| NGN | 15,000 / 150,000 | 29,000 / 290,000 | 45,000 / 450,000 |
| GHS | 250 / 2,500 | 480 / 4,800 | 750 / 7,500 |
| KES | 2,500 / 25,000 | 4,900 / 49,000 | 7,500 / 75,000 |
| ZAR | 350 / 3,500 | 690 / 6,900 | 1,050 / 10,500 |
| GBP | 16 / 160 | 31 / 310 | 47 / 470 |
| EUR | 18 / 180 | 36 / 360 | 55 / 550 |

With NGN prices in place, your unpaid store (NGN) now sees and pays NGN (§8.0). Add `NGN` to each gateway's `supported_currencies` (§8.2) so it can charge them.

---

## 10. Selling in more currencies (multi-currency) ➡ you

Your store's **base currency** (NGN for Softmaxtech) is what accounting and reports use. Multi-currency lets shoppers buy in other currencies too. It's included from the **Standard** plan.

### 10.1 Set it up (store admin, owner token)

```http
POST /admin/modules/multi_currency/enable
POST /admin/currencies                          { "currency_code": "USD", "display_symbol": "$", "rate": "0.00065" }
```

`rate` means **1 base = rate of this currency**, so 1 NGN = 0.00065 USD, or about ₦1,538 per $1.

A currency is offered to shoppers only when it is **active and has a rate**. `GET /admin/currencies` shows `is_offered` for each.

**Rates:**

| How | Request | Notes |
|---|---|---|
| By hand (default) | `PUT /admin/currencies/{id}/rate { "rate": "0.00065" }` | `rate_source: manual`. Never overwritten automatically. |
| Automatically, daily | Set `FX_PROVIDER=open_er_api` in `.env` (free, no key) | Fetched once a day by the store's daily maintenance, or now with `POST /admin/currencies/refresh-rates` |
| Back to automatic | `DELETE /admin/currencies/{id}/rate` | The provider fills it on the next refresh |

**Fixed market prices (optional):** set a deliberate price per product (or variant) and currency. It always wins over the converted estimate:

```http
POST  /admin/products/{product}/prices          { "currency_code": "USD", "price": "30", "compare_at_price": "35", "product_variant_id": null }
PATCH /admin/products/{product}/prices/{price}  { "price": "29" }
GET   /admin/products/{product}/prices
```

Other routes:
- `PATCH /admin/currencies/{id}/deactivate` stops offering a currency. Carts in it fall back to the base currency.
- `POST /admin/currencies/{id}/set-base` changes the base currency, **only before the first order or journal entry**.
- `GET /admin/accounting/reports/currency-exposure` shows unpaid balances by currency, with their base value. It needs accounting.

### 10.2 What shoppers get (storefront)

- `GET /storefront/config` returns `formatting.currencies`, the currencies on offer. Use it for a currency switcher.
- Catalogue pages take `?currency=USD`: `GET /products?currency=USD`, `/products/{slug}?currency=USD`, and so on. Each price comes with `currency_code` and `is_estimated`. `true` means converted at the rate, so show it as "≈ $3.25". `false` means your fixed price, or the base currency.
- `PATCH /cart/currency { "currency_code": "USD" }` switches the cart. Its quote is then fully in USD: items, shipping (converted), discounts and tax.
- The order is created and paid in USD. Only payment providers that support USD are offered.
- The order stores the rate used and its total in NGN (`exchange_rate_used`, `base_currency_amount`). These never change afterwards; accounting and reports use them.

### 10.3 After pulling this step ➡ you (once)

```powershell
php artisan tenants:migrate        # adds the currency tables
php artisan config:clear
```

Then restart the queue worker. The base currency row is created automatically.

---

## 11. Buying stock from suppliers (purchasing) ➡ you

Purchasing is included from the **Standard** plan. Enable it with `POST /admin/modules/purchasing/enable`. Five default return reasons are created the first time.

### 11.1 The everyday flow

```http
POST  /admin/suppliers                          { "name": "Lagos Leather", "email": "sales@leather.test", "payment_terms": "Net 30" }
POST  /admin/suppliers/{id}/products            { "product_id": 12, "supplier_sku": "LL-RUN", "cost_price": "18000" }
GET   /admin/purchase-orders/product-lookup?q=run        (product picker, with stock on hand)
POST  /admin/purchase-orders                    { "supplier_id": 1, "warehouse_id": 1, "items": [{ "product_id": 12, "quantity": 10 }] }
PATCH /admin/purchase-orders/{id}/submit
PATCH /admin/purchase-orders/{id}/receive       { "items": [{ "purchase_order_item_id": 5, "quantity": 4 }] }
```

- A line without `unit_cost` uses the supplier's cost for that product, then the product's cost price.
- Receiving **adds stock** to the order's warehouse. You can receive in parts: the status goes `partially_received`, then `received`. Staff get a "purchase order received" notification.
- Only drafts can be edited. Only drafts or submitted orders with nothing received can be cancelled. `POST …/duplicate` copies an order as a new draft.

### 11.2 Paying suppliers and balances

```http
POST /admin/suppliers/{id}/payments             { "purchase_order_id": 1, "amount_paid": "30000", "payment_method": "bank_transfer", "reference": "TRF-1" }
POST /admin/suppliers/{id}/payments             { "amount_paid": "5000", "payment_method": "cash" }        (on account: no order)
GET  /admin/purchase-orders/{id}/balance        (order currency)
GET  /admin/suppliers/{id}/balance              (store base currency)
GET  /admin/supplier-payments/outstanding       (every supplier you owe, largest first)
```

Payments are recorded only: they happen outside the platform, by transfer, cash or cheque. Editing or deleting one corrects the accounts automatically.

### 11.3 Returns to the supplier

```http
POST  /admin/purchase-returns                   { "purchase_order_id": 1, "purchase_return_reason_id": 1, "items": [{ "purchase_order_item_id": 5, "quantity": 2 }] }
PATCH /admin/purchase-returns/{id}/approve      (stock leaves the warehouse now)
PATCH /admin/purchase-returns/{id}/ship-back
POST  /admin/purchase-returns/{id}/refund       { "resolution": "refund", "payment_method": "bank_transfer" }   or { "resolution": "credit_note" }
PATCH /admin/purchase-returns/{id}/close
```

- A **refund** records the money you got back, as a negative payment on the order.
- A **credit note** leaves the amount as a credit that reduces what you owe on the order.

### 11.4 Quotations (optional, before ordering)

```http
POST  /admin/quotation-requests                 { "warehouse_id": 1, "respond_by": "2026-10-10", "items": [{ "product_id": 12, "quantity": 20 }] }
POST  /admin/quotation-requests/{id}/send       { "supplier_ids": [1, 2] }      (emails each supplier the item list)
PATCH /admin/supplier-quotations/{id}/record    { "valid_until": "2026-10-20", "items": [{ "quotation_request_item_id": 3, "unit_price": "17000", "lead_time_days": 5 }] }
PATCH /admin/supplier-quotations/{id}/accept    (creates a draft purchase order at the quoted prices)
```

Suppliers answer by replying to the email; staff enter the prices. A quote must price every line before it can be accepted. Quotes past `valid_until` expire overnight.

### 11.5 Optional extras

- **Foreign-currency orders:** send `"currency_code": "USD"` when creating an order, or set the `default_purchase_order_currency` setting. The currency needs a rate (§10.1) before the order can be submitted. The rate is fixed at submission, and payments on that order use it.
- **Approval for large orders** (needs `approval_workflows`, Premium):

  ```http
  POST /admin/approval-workflows   { "name": "Big purchases", "module_key": "purchase_order", "trigger_conditions": { "min_amount": 500000 }, "steps": [...] }
  ```

  Receiving waits for the approval (`409 approval_pending`). A rejection cancels the order.
- **Accounting (if enabled):**

  | Event | Entry |
  |---|---|
  | Receiving | Dr Inventory, Cr Accounts Payable |
  | A payment | Dr Accounts Payable, Cr Cash/Bank |
  | A return | Dr Accounts Payable, Cr Inventory |
  | A refund | Dr Cash/Bank, Cr Accounts Payable |

### 11.6 After pulling this step ➡ you (once)

```powershell
php artisan tenants:migrate
```

Then restart the queue worker.

---

## 12. Gift cards, reward points and installments ➡ you

Gift cards and reward points are included from the **Standard** plan; installments need **Premium**. Enable each one you want:

```http
POST /admin/modules/gift_cards/enable
POST /admin/modules/reward_points/enable
POST /admin/modules/installments/enable
```

> **Test mode matters here.** A new store starts in payment **test mode**, and every order it takes is a test order. Test orders never use or create real stored value. So while you are in test mode:
>
> - gift cards can't be applied to a cart (reason `test_mode`);
> - buying a gift card issues no card;
> - points can't be redeemed or earned.
>
> Installments work in test mode, using your gateway's test keys. To try cards and points end to end, switch the store to live mode (`PATCH /admin/payment-settings/mode`).

### 12.1 Gift cards

```http
POST  /admin/gift-cards                  { "initial_value": "5000", "recipient_email": "friend@mail.test", "expires_at": "2027-12-31" }   (Idempotency-Key header)
GET   /admin/gift-cards                  (lists show only the last 4 characters)
PATCH /admin/gift-cards/{id}/disable
GET   /gift-cards/{code}/balance         (public, rate-limited)
POST  /cart/apply-gift-card              { "code": "ABCD…" }
DELETE /cart/gift-card
POST  /gift-cards/purchase               { "amount": "10000", "recipient_email": "friend@mail.test", "recipient_message": "Happy birthday" }   (Idempotency-Key header)
```

- **Copy the code when you issue a card.** It is shown in full only once; the recipient also gets it by email.
- **The cart shows the split.** Once a card is applied, the quote shows `gift_card_amount_applied` and `amount_due` (what is still left to pay). The card becomes a payment on the order when the order is placed.
- **Cancelling puts the value back.** If the order is cancelled, the amount goes back onto the card. A customer can cancel an order that was paid only by gift card.
- **Buying a card creates an order.** Pay it like any other order (`POST /orders/{id}/pay`, or record a payment in the admin). The card is issued and emailed when the order is paid.
- Cards past their expiry date expire overnight.

### 12.2 Reward points

```http
PUT  /admin/reward-points/settings       { "is_active": true, "amount_per_point": "100", "redeem_amount_per_point": "1", "minimum_redeem_points": 50, "point_expiry_days": 365 }
POST /admin/customers/{id}/reward-points/adjust   { "points": 200, "reason": "Welcome bonus" }
GET  /account/reward-points/balance      (customer token)
GET  /account/reward-points/history
POST /cart/apply-reward-points           { "points": 100 }     (0 removes them)
```

- **Earning.** With the settings above, a customer earns 1 point per 100 spent, on the items' amount without tax, after any points discount. Points are added once the order is delivered or completed.
- **Redeeming.** Each point is worth 1 off. The discount is taken at checkout, and the points come back if the order is cancelled.
- **Expiry.** Points expire `point_expiry_days` after they were earned. The check runs nightly.

### 12.3 Installments (pay over time)

First switch it on, and optionally set a minimum order amount:

```http
PATCH /admin/settings   { "installments_enabled": true, "installments_minimum_order_amount": "50000", "installments_fulfillment_policy": "on_full_payment" }
```

The customer then chooses installments at checkout, or right after placing the order:

```http
POST /orders   { "quote_hash": "…", "installment_plan": { "number_of_installments": 3, "frequency": "monthly" } }
GET  /orders/{id}/installment-eligibility
POST /orders/{id}/installment-plan       { "number_of_installments": 3, "frequency": "monthly" }
GET  /orders/{id}/installment-plan
POST /installment-payments/{id}/pay      { "gateway": "paystack" }   (Idempotency-Key header)
```

- **The split.** The total is split evenly, and any rounding difference goes on the last installment. The first installment is due today.
- **Later installments are charged automatically.** When the customer pays the first installment through the gateway, their card is saved. Each later installment is charged on its due date by the scheduler (`schedule:work` locally).
- **If a charge fails,** the customer can pay that installment with the same `/pay` call.
- **Overdue and defaulted.** An unpaid installment past its due date becomes `overdue`. After `installments_default_after_overdue_count` overdue installments (default 2), the plan is flagged `defaulted` for staff. Nothing is cancelled automatically.
- **When the order is released:**
  - `on_full_payment` (the default): the order is confirmed and shipped once everything is paid;
  - `on_first_payment`: the order is confirmed after the first installment.
- A gift card and an installment plan can't be combined in one checkout.

Staff:

```http
GET   /admin/installment-plans?status=defaulted
POST  /admin/installment-plans/{plan}/payments/{payment}/charge   (charge now with the saved card; Idempotency-Key header)
PATCH /admin/installment-plans/{plan}/cancel
```

### 12.4 After pulling this step ➡ you (once)

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults     # new permissions and email templates for stores that already exist (runs on the queue worker)
```

Then restart the queue worker and `schedule:work`. Installment charges, and gift-card and points expiry, run from the daily tenant maintenance.

> `tenants:sync-defaults` also covers steps 10 and 11. If you pulled those without running it, your existing store's owner gets `403 forbidden` on the currency and purchasing admin routes (the response names the missing `permission`). New stores get everything when they are created.

---

## 13. Selling in a shop (point of sale) ➡ you

POS is included from the **Standard** plan: 3 registers on Standard, 10 on Premium. A POS sale is a normal order (`order_source = pos`), so it shows up in orders, stock and reports. Cashiers are staff users; give them a role with the `pos.*` permissions.

```http
POST /admin/modules/pos/enable
PUT  /admin/pos/settings   { "default_customer_id": 12, "enabled_payment_methods": ["cash", "bank_transfer", "card_terminal", "gift_card", "reward_points", "credit_sale"] }
POST /admin/pos/registers  { "warehouse_id": 1, "name": "Front Counter" }
```

- **Walk-in customer.** Create an ordinary customer called "Walk-in Customer" and set it as `default_customer_id`. Sales without a chosen customer go to it.
- **Tax.** POS charges tax at the register's warehouse, so give the warehouse a country (and state).
- **Cash drawer.** `cash_register_enabled` is on by default: every sale then needs an open session. Turn it off for card-only shops.

### 13.1 A shift at the till

```http
POST /admin/pos/sessions                    { "register_id": 1, "opening_cash_float": "10000" }
GET  /admin/pos/products/lookup?barcode=5901234123457&register_id=1     (or ?q=runner)
POST /admin/pos/quote                       { "register_id": 1, "lines": [{ "product_id": 7, "quantity": 2 }] }
POST /admin/pos/sales                       { "register_id": 1, "idempotency_key": "<uuid from the till>", "quote_total": "86000",
                                              "lines": [{ "product_id": 7, "quantity": 2 }],
                                              "payments": [{ "method": "cash", "amount": "100000" }] }
GET  /admin/pos/sales/{id}/receipt
POST /admin/pos/sessions/{id}/close         { "closing_cash_float": "96000" }      (what you counted in the drawer)
```

- **Cash and change.** Enter the cash the customer handed over. The response shows `change_given`.
- **Paying several ways.** Put more than one entry in `payments`, for example part bank transfer and part cash. Only cash may be more than the total.
- **The sale response** includes the receipt data and the warehouse's receipt printer, if one is set up in Documents.
- **Closing the drawer.** Closing a session shows the expected cash and the variance. Set `pos_cash_variance_threshold` in `PATCH /admin/settings` to alert staff when the variance is larger than that amount.
- **Credit sales** (`"credit_sale": true`) and **reward points** (`"reward_points": 100`) need a real customer, not the walk-in record.
- **Gift cards.** Add a payment with `"method": "gift_card", "gift_card_code": "…"`.
- **Receipt by email or SMS.** Turn on `send_sms_after_sale` in the POS settings. Known customers then get their receipt.

### 13.2 Card terminals (Moniepoint, OPay, Stripe Terminal)

Card terminals need live mode: the store must be in live payment mode, because there is no test mode for terminals.

```http
PATCH /admin/pos/registers/1/terminal   { "provider": "opay", "credentials": { "merchant_id": "…", "secret_key": "…", "terminal_serial": "N7810…" } }
POST  /admin/pos/terminal-charges       { "register_id": 1, "amount": "43000" }       → reference PTC-…, the terminal asks for the card
GET   /admin/pos/terminal-charges/PTC-…?register_id=1                                    (poll until "successful")
POST  /admin/pos/sales                  { …, "payments": [{ "method": "card_terminal", "amount": "43000", "reference": "PTC-…" }] }
```

| Provider | Credentials | Where to find them |
|---|---|---|
| `moniepoint` | `client_id`, `client_secret`, `terminal_serial` | Moniepoint business account → API keys. Also turn on **ERP integration** under POS terminal features. |
| `opay` | `merchant_id`, `secret_key`, `terminal_serial` | OPay Business Dashboard → Developer Tools, as Super Admin. |
| `stripe_terminal` | `secret_key`, `reader_id` | Stripe dashboard → Developers (keys); Terminal → Readers (`tmr_…`). |

- **Credentials are write-only.** Responses show only `has_terminal_credentials`.
- **Each card payment pays one sale.** Reusing a reference fails with `terminal_charge_used`.
- **Moniepoint (check before going live).** We send amounts in kobo. Try one small real sale first and check the amount on the terminal.

### 13.3 Voids and offline sales

```http
POST /admin/pos/sales/{id}/void   { "reason": "Wrong size" }
```

- **When you can void.** While the sale's session is open. Without sessions, within 24 hours. After that, use a return.
- **What a void does.** Cash and bank payments are refunded in the books, and OPay and Stripe card payments are refunded through the provider. Stock goes back, a gift card gets its value back, and points go back to how they were before the sale.
- **Moniepoint can't refund through its API.** Reverse the payment on the terminal first, then void with `"terminal_reversed": true`.
- **Offline sales.** The till can save sales while offline and send them later with the same body plus `sold_at` (when the sale happened) and `pos_session_id`.
  - The same `idempotency_key` never makes a second sale: a repeat returns the first sale with status 200.
  - If a promotion ran out while you were offline, the sale is still accepted, and staff get a "limit exceeded offline" notice.
  - If there isn't enough stock, the sale is refused with `409 stock_conflict`. Keep it on the till and fix the stock first.

### 13.4 After pulling this step ➡ you (once)

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults     # POS permissions and the pos.sale_receipt template
php artisan config:clear
```

Then restart the queue worker. Add the `POS_*` lines from `.env.example` to your `.env` if you want to change the defaults.

---

## 14. Sales agents and commissions ➡ you

Sales agents need the **Premium** plan. An agent is a referral or sales rep who earns a commission on sales the store fulfils; agents don't need a login.

```http
POST  /admin/modules/sales_agents/enable
PATCH /admin/settings                              { "default_sales_agent_commission_rate": "5" }        (percent)
POST  /admin/sales-agents                          { "name": "Bola Ade", "phone": "+234…", "email": "bola@…", "commission_rate": "7.5" }   (rate optional)
GET   /admin/sales-agents?status=active&search=bola
```

The response includes `agent_code`, for example `BOLAX7K2`. Give it to the agent; it works in any letter case.

**Crediting a sale to an agent** (only before the order is confirmed):

| Where | How |
|---|---|
| Online checkout | `"sales_agent_code": "BOLAX7K2"` in `POST /orders` |
| POS | `"sales_agent_id": 3` in `POST /admin/pos/sales` |
| Staff, on an unpaid order | `PATCH /admin/orders/{id}/sales-agent { "sales_agent_id": 3 }` (use `null` to clear) |

**Commissions:**

- **When it's earned.** When the order is confirmed (paid), the agent earns: the items' amount without tax, shipping or discounts, in store currency, times their rate. It starts as `pending`.
- **Approve, then pay.** Approve the commission, pay the agent outside the platform, then mark it paid:

  ```http
  GET   /admin/sales-agents/{id}/commissions?status=pending
  PATCH /admin/sales-agent-commissions/{id}/approve
  PATCH /admin/sales-agent-commissions/{id}/mark-paid
  GET   /admin/sales-agents/{id}/balance          (outstanding = approved but not yet paid)
  GET   /admin/dashboard/sales_agents?range=this_month
  ```
- **Test orders earn nothing.**
- **Refunds don't change commissions automatically.** If a sale is refunded, simply don't approve its commission.

After pulling this step:

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults
```

---

## 15. Sales quotations (quotes before ordering) ➡ you

Sales quotations are included from the **Standard** plan. They are for bulk or negotiated prices: the customer asks for a price, the store sends a quote, and an accepted quote becomes a normal order.

```http
POST /admin/modules/sales_quotations/enable
PATCH /admin/settings   { "allow_quotation_without_stock": false }    (optional: refuse to quote items that are out of stock)
```

**Customer side** (customer token):

```http
POST /quotation-requests                  { "items": [{ "product_id": 7, "quantity": 10 }], "notes": "Bulk for our team", "sales_agent_code": "BOLAX7K2" }
GET  /quotation-requests                  (includes each quote once it has been sent)
POST /quotation-requests/{id}/accept      { "address_id": 3 }       (optional: the address used for tax; default is their default address)
POST /quotation-requests/{id}/reject
```

**Store side:**

```http
POST /admin/sales-quotation-requests                  { "customer_id": 5, "items": [...], "sales_agent_id": 3, "draft": true }   (phone or walk-in enquiries; customer_id is optional)
POST /admin/sales-quotation-requests/{id}/send        { "items": [{ "request_item_id": 11, "unit_price": "35000", "discount_amount": "5000" }], "valid_until": "2026-10-31" }
POST /admin/sales-quotation-requests/{id}/cancel      (before a quote is sent)
POST /admin/sales-quotations/{id}/accept              (on the customer's behalf)
POST /admin/sales-quotations/{id}/reject
GET  /admin/dashboard/sales_quotations?range=this_month
```

- **Sending a quote.** Price every requested line. The quote gets a number like `SQ-000001`, and the customer receives the "quotation sent" email with a link to `/account/quotations/{id}` on the storefront.
- **Accepting a quote** creates a **pending** order:
  - it uses the quoted prices and discounts, with no promotions on top;
  - tax is worked out on the customer's address, and there's no shipping charge;
  - stock is reserved, and the sales agent is credited;
  - the order doesn't expire unpaid. The customer pays the normal way, or you record a bank transfer in the admin.
- **Expiry.** A quote past its `valid_until` date can't be accepted, and it expires overnight.

After pulling this step:

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults
```

---

## 16. Marketplace: other sellers on your store ➡ you

The marketplace needs the **Premium** plan (up to 100 approved sellers). Sellers list products under your storefront. **You** hold their stock in your warehouses and ship their orders; they get paid their share minus your commission.

```http
POST /admin/modules/marketplace/enable
PATCH /admin/settings   { "default_seller_commission_rate": "10", "seller_product_approval_required": true, "seller_payout_hold_days": 14 }
```

### 16.1 Seller sign-up and approval

```http
POST /seller/auth/register    { "business_name": "Ade Crafts", "email": "…", "password": "…", "password_confirmation": "…" }   (seller, no token yet)
GET  /admin/sellers?status=pending
POST /admin/sellers/{id}/approve          (or /reject { "reason": "…" }, /suspend)
POST /seller/auth/login                   (only approved sellers)
```

- **Commission rates.** Set a better rate for a set of sellers with a group: `POST /admin/seller-groups { "name": "Verified", "default_commission_rate": "8" }`, then `POST /admin/sellers/{id}/assign-group`.
- **One seller's own rate** takes priority: `PATCH /admin/sellers/{id}/commission-rate`.
- **Suspending a seller** signs them out and takes their products off sale.
- **Approval workflow (optional).** To have a second person approve new sellers, create an approval workflow with `module_key: seller_application`.

### 16.2 What a seller does (seller token)

```http
GET/PATCH /seller/profile                 (shows their rate, group and balance)
POST      /seller/products                { "name": "Woven Basket", "price": "10000", "is_active": true }
GET       /seller/orders                  (their lines only; no customer details)
GET       /seller/ledger, /seller/payouts
POST      /seller/product-questions/{id}/answers { "answer": "…" }
```

- **Any product type.** Sellers can list simple, variable (sizes, colours), digital and bundle products, and manage them as your staff do:

  ```http
  POST /seller/products                              { "product_type": "variable", "name": "Adire Shirt", "price": "15000" }
  GET  /seller/product-options                       (your store's options; sellers can't change them)
  POST /seller/products/{id}/variants                { "sku": "ADIRE-S", "price": "15000", "option_value_ids": [3] }
  POST /seller/products/{id}/media                   (image upload)
  POST /seller/products/{id}/digital-files           (file upload, digital products)
  POST /seller/products/{id}/bundle-items            { "child_product_id": 12, "quantity": 1 }   (their own products only)
  PUT  /seller/products/{id}/specifications
  ```
- **What sellers can't set:** warehouse pricing, their seller id, bookable, subscribable, or sales-channel options. Badges and related products stay with your staff.
- **New products wait for approval.** With `seller_product_approval_required` on, a new product is hidden until you approve it: `GET /admin/seller-products`, then `POST /admin/seller-products/{id}/approve` or `/reject { "moderation_note": "…" }`.
- **Stock is yours to manage.** Receive the seller's goods into a warehouse as normal stock (stock adjustment or purchase order).

### 16.3 Earnings and payouts

- **Earnings.** For each sale of a seller's product, the seller earns the line amount (before tax, minus any seller-funded discount) minus your commission.
- **When they become payable.** Earnings can be paid once the order is complete and `seller_payout_hold_days` has passed. If that isn't set, your return window applies.
- **Refunds, returns, cancellations and chargebacks** take back the seller's share automatically. The same money is never taken back twice.
- **Paying a seller.** Pay outside the platform (bank transfer), then:

  ```http
  POST /admin/sellers/{id}/payouts              { "from": "2026-10-01", "to": "2026-10-31", "preview": true }   (totals only)
  POST /admin/sellers/{id}/payouts              { "from": "2026-10-01", "to": "2026-10-31" }
  POST /admin/sellers/{id}/payouts/{payout}/mark-paid   { "reference": "TRF-501" }   (Idempotency-Key header)
  GET  /admin/dashboard/marketplace?range=this_month
  ```
- **With accounting on,** the seller's share moves from sales revenue into commission revenue and "payable to sellers", and marking a payout paid clears the payable.

### 16.4 Switching the marketplace off

- **Pay first.** You can't disable the marketplace while any seller is still owed money (`422 module_disable_blocked`). Pay them out first. In the same way, POS can't be disabled while a register session is open.
- **What happens when it's off:**
  - seller products disappear from the storefront, carts and checkout, but stay in your admin catalogue;
  - sellers can still sign in, see their products, orders, ledger and payouts, and you can still pay them;
  - sellers can't register or change products.

Helpful extras: `GET /admin/sellers/metrics` (the KPI strip for the sellers list), `GET /admin/lookups/seller-groups`, and `GET /admin/orders?seller_id=3`.

After pulling this step:

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults
```

---

## 17. Subscribe and save, and "notify me when it's back" ➡ you

### 17.1 Product subscriptions

Product subscriptions need the **Premium** plan. A customer gets a product delivered on a schedule ("coffee every month"), at a discount you choose. Every delivery is a normal order: it reserves stock, ships and shows up in your order list like any other.

```http
POST  /admin/modules/product_subscriptions/enable
PATCH /admin/products/{id}                          { "is_subscribable": true, "subscription_discount_percent": 10 }
POST  /admin/products/{id}/subscription-plans       { "interval": "monthly" }            (weekly, biweekly, monthly or quarterly)
POST  /admin/products/{id}/subscription-plans       { "interval": "monthly", "interval_count": 2 }   (every two months)
DELETE /admin/products/{id}/subscription-plans/{plan}   (stops new sign-ups; existing subscriptions carry on)
```

**Customer side** (customer token):

```http
GET    /products/{slug}/subscription-plans          (no token needed: the schedules and the discount)
POST   /account/product-subscriptions               { "product_id": 7, "product_subscription_plan_id": 2, "quantity": 2, "address_id": 3, "shipping_method_id": 1 }
POST   /orders/{order}/pay                          { "gateway": "paystack" }   (the order returned above; this saves the card)
GET    /account/product-subscriptions
PATCH  /account/product-subscriptions/{id}          { "quantity": 3 }   (also product_variant_id, address_id, shipping_method_id)
PATCH  /account/product-subscriptions/{id}/pause    (and /resume)
DELETE /account/product-subscriptions/{id}          (cancels)
```

- **Starting.** The subscription starts when the first order is paid online with a card the provider can save. If the provider can't save the card, the order still ships, but the subscription doesn't start and the customer is told.
- **Renewals.** The overnight job creates each renewal order at that day's price, minus the subscription discount, and charges the saved card. The queue worker and scheduler must be running.
- **A failed charge** is retried each night. The customer is told each time, and the subscription is cancelled after `product_subscription_max_failed_renewals` failures (default 3). The unpaid renewal order is cancelled, so its stock goes back.
- **Out of stock on renewal day?** The renewal waits and is tried again each night. It doesn't count as a failed payment, and staff can see why in `last_renewal_error`.
- **Resuming** a paused subscription restarts the schedule from the day it's resumed.

**Store side:**

```http
GET   /admin/product-subscriptions?status=payment_failed
GET   /admin/product-subscriptions/{id}               (includes every order it made)
PATCH /admin/product-subscriptions/{id}/cancel        (the customer is emailed)
GET   /admin/product-subscriptions/metrics
GET   /admin/dashboard/product_subscriptions?range=this_month
```

You can't switch the module off while any subscription is still running (`422 module_disable_blocked`): cancel them first. If your plan changes and the module becomes locked, existing subscriptions keep renewing.

### 17.2 Back-in-stock alerts

Back-in-stock alerts come with the **Standard** plan and above, and are switched on automatically. On an out-of-stock product, a shopper, even one without an account, leaves an email address:

```http
POST /products/{slug}/back-in-stock-alert           { "email": "…", "product_variant_id": 12 }   (variant optional; a signed-in customer can leave out the email)
GET  /admin/products/{id}/back-in-stock-subscribers  (who is waiting, by size or colour)
```

- **When it's sent.** As soon as stock comes back (a stock adjustment, a purchase receipt, a return), everyone waiting gets one email.
- **Bundles.** Restocking a part of a bundle also alerts people waiting for the bundle, once the bundle can be made.
- **Refused requests.** The request is refused if the item is in stock or never runs out (digital and service products).
- **Switching it off:** `POST /admin/modules/back_in_stock_alerts/disable`.

After pulling this step:

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults
php artisan config:clear
```

Then restart `queue:work` and `schedule:work`.

---

## 18. Staff, customer support and projects ➡ you

All three need the **Premium** plan. Each is switched on separately.

### 18.1 HR: people, attendance and leave

```http
POST  /admin/modules/hr/enable
POST  /admin/hr/departments                        { "name": "Sales" }
POST  /admin/hr/employees                          { "user_id": 4, "department_id": 1, "employment_type": "full_time", "hire_date": "2026-01-05" }   (someone who logs in)
POST  /admin/hr/employees                          { "first_name": "Chidi", "last_name": "Obi", "employment_type": "contract", "hire_date": "2026-03-01" }   (someone who doesn't)
PUT   /admin/hr/settings                           { "expected_clock_in_time": "09:00", "late_grace_minutes": 10 }
PUT   /admin/hr/departments/{id}/settings          { "expected_clock_in_time": "08:00" }   (this department's own hours)
```

- **Employees who have a login** are linked to their staff user, and their name and email come from that account. Everyone else is a standalone employee.
- **The plan's employee limit** counts active employees only.
- **Clocking in and out:** each person clocks themselves in and out with `POST /admin/hr/attendance/clock-in` and `/clock-out`. At a shared kiosk, a manager holding `hr.attendance.record` sends `{ "employee_id": 7 }` instead.
- **Lateness and leaving early** are worked out from the department's hours, falling back to the store's hours, in the store's timezone.
- **Leave:**
  1. Create the leave types: `POST /admin/hr/leave-types { "name": "Annual", "days_per_year": 10 }`.
  2. Staff ask for leave for themselves: `POST /admin/hr/leave-requests { "leave_type_id": 1, "start_date": "…", "end_date": "…" }`. Weekdays are counted; send `"days": 0.5` for a half day.
  3. A manager approves or rejects it (`POST /admin/hr/leave-requests/{id}/approve`, `/reject { "reason": "…" }`).
  4. To route long leave through someone else, create an approval workflow with `module_key: leave_request` and `trigger_conditions: { "min_days": 5 }`.
- **Documents:** `POST /admin/hr/employees/{id}/documents` takes a multipart upload (`file`, `document_type_id`, `expiry_date`). See what expires soon with `GET /admin/hr/documents/expiring?within_days=30`.
- **Appraisals:**
  - A template's criteria weights must add up to 100.
  - A manager scores each criterion, then submits, which gives an overall score out of 100.
  - The employee acknowledges it with `POST /admin/hr/appraisals/{id}/acknowledge`.

### 18.2 Payroll (the `hr_payroll` add-on)

```http
POST /admin/modules/hr_payroll/enable
POST /admin/hr/employees/{id}/salary              { "base_salary": "350000", "effective_from": "2026-10-01" }   (the old salary ends the day before)
POST /admin/hr/payroll-runs                       { "period_start": "2026-10-01", "period_end": "2026-10-31" }
POST /admin/hr/payroll-runs/{run}/generate-items  (one payslip per active employee with a salary)
POST /admin/hr/payroll-items/{item}/lines         { "type": "tax", "label": "PAYE", "amount": "10", "is_percentage": true }
POST /admin/hr/payroll-runs/{run}/finalize        (locks the run; no more changes)
POST /admin/hr/payroll-runs/{run}/mark-paid       (Idempotency-Key header)
GET  /admin/hr/employees/{id}/payslips/{run}
```

- **Line types:** allowance, bonus and reimbursement add to gross pay; deduction and tax come off it. Net pay can't go below zero.
- **Pay periods** can't overlap, so nobody is paid twice for the same days.
- **With accounting on,** paying a run posts one entry: salaries and wages at gross, cash at net, and payroll liabilities for everything withheld.
- **Switching payroll off** is blocked while a finalized run is still unpaid.

### 18.3 Recruitment (the `hr_recruitment` add-on)

```http
POST /admin/modules/hr_recruitment/enable
POST /admin/hr/job-postings                       { "title": "Store Manager", "description": "…", "employment_type": "full_time", "application_deadline": "2026-11-15" }
POST /admin/hr/job-postings/{id}/publish
GET  /careers/jobs                                 (public; no token)
POST /careers/jobs/{slug}/apply                    (public, multipart: first_name, last_name, email, resume, cover_letter?)
PATCH /admin/hr/applications/{id}/status          { "status": "hired" }
POST /admin/hr/applications/{id}/convert-to-employee   { "hire_date": "2026-11-01" }
```

- **CVs** download with `GET /admin/hr/applications/{id}/resume`.
- **Internal notes** on an application are never shown to the applicant.

### 18.4 Customer support

```http
POST /admin/modules/support/enable
```

**Customers and guests** (no account needed; send the guest's `X-Guest-Token`):

```http
POST /support/conversations                       { "channel": "chat", "body": "Do you deliver to Abuja?" }
POST /support/conversations                       { "channel": "ticket", "subject": "Refund", "body": "…", "guest_name": "…", "guest_email": "…" }
POST /support/conversations/{id}/messages          (multipart: body, attachments[])
```

- **A guest without a token** gets one back as `guest_token`. When they sign in with it, their conversations move to their account.

**Staff:**

```http
GET   /admin/support/conversations?assigned_to=unassigned   (or me, or a user id)
PATCH /admin/support/conversations/{id}            { "assigned_to_user_id": 1, "status": "resolved", "priority": "high" }
POST  /admin/support/conversations/{id}/messages   { "body": "…" }
POST  /admin/support/conversations/{id}/notes      { "body": "…" }   (staff only)
```

- **Live updates** use Reverb.
  - Staff authorise at `/api/broadcasting/auth`; customers and guests at `/api/support/broadcasting/auth`.
  - The channels are `tenant.{id}.support-conversation.{id}`, `tenant.{id}.support-inbox` and the presence channel `tenant.{id}.support-agents-online`.
  - Typing indicators are client whispers.
- **When someone isn't online,** they're emailed at most once every 15 minutes per conversation.

### 18.5 Projects

```http
POST  /admin/modules/project_management/enable
POST  /admin/project-categories                   { "name": "Fit-outs" }
POST  /admin/projects                             { "title": "Lekki shop fit-out", "project_category_id": 1, "customer_id": 5, "user_ids": [3, 4], "notify_assigned_employees_whatsapp": true }
POST  /admin/projects/{id}/tasks                  { "title": "Order shelving", "assigned_user_id": 3, "end_date": "2026-10-20", "send_whatsapp_notification": true }
PATCH /admin/projects/{id}/status                 { "status": "in_progress" }
```

- **Who can see what:** with `staff_data_access_scope` set to `own` or `warehouse`, staff other than owners and admins only see the projects they're on or created, and only their own tasks.
- **Notifications:** the WhatsApp switches decide whether people are notified at all. Delivery follows your notification settings.

After pulling this step:

```powershell
php artisan tenants:migrate
php artisan tenants:sync-defaults
```

---

## 19. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `503 registration_unavailable` | The Terms or Privacy Policy is not published (§4.2), or `tenant_registration_enabled` is `false`. |
| `403 forbidden` with a `permission` in `meta.details`, on a newly added admin route (owner token) | The store was created before that route existed. Run `php artisan tenants:sync-defaults` with the queue worker running (§12.4). |
| `422 legal_version_outdated` on `/register` | `accepted_legal_document_ids` must be exactly the current published ids from `GET /legal-documents/current`. |
| No code in `laravel.log` | The queue worker isn't running (§3.1). Check `php artisan queue:failed` too. |
| Status stays `provisioning` | The worker isn't running, or the job failed. Run `php artisan queue:failed`, fix the cause, then `php artisan queue:retry all`. |
| `The remote name could not be resolved` / Postman "could not get response" for the store domain | Add the hosts line (§3.3). |
| `404 not_found` on every store route | The domain doesn't match a tenant. Use exactly the `domain` returned by `/register/verify`. |
| `403 feature_unavailable` / `module_disabled` | The store's plan doesn't include that module, or it isn't enabled. Enable it with `POST /admin/modules/{key}/enable` (store admin) where the plan allows. |
| Accounting postings show `failed` | The store has no fiscal year covering the date. Create one with `POST /admin/accounting/fiscal-years`, then `POST /admin/accounting/posting-requests/retry`. |
| `422 gateway_unavailable` on subscribe | That provider isn't enabled in the current billing mode, or its `supported_currencies` doesn't include the price currency (USD). The error lists the providers that would work (§8.2). |
| `422 payment_mode_mismatch` when saving keys | You pasted live keys into the `test` slot, or the other way round (§8.2). |
| `422 gateway_not_verified` on enable | The keys weren't accepted, or were checked more than 24 hours ago. Run `POST …/test` again, then enable. |
| `409 payment_in_progress` | A checkout from the last 30 minutes is still pending. Finish it, wait, or confirm it (§8.6). |
| `409 subscription_active` on `POST /admin/billing/subscription` | The store has already paid. Use swap-plan (§8.5, path B). |
| `422 payment_method_required` on swap-plan | The store has never paid through a gateway, so there's no saved card to charge. Pay once with path A first. |
| `422 currency_not_supported` on `?currency=` or `PATCH /cart/currency` | The currency isn't offered: `multi_currency` is off, the currency isn't added or is inactive, or it has no rate yet (§10.1). |
| `409 approval_pending` on receive | A purchase-order approval workflow is still deciding the order. Decide it in `/admin/approvals` (§11.5). |
| `422 exchange_rate_unavailable` on submit | The order's currency has no rate. Add one under Currencies (§10.1), or order in the base currency. |
| `422 base_currency_locked` | The base currency can't change once any order or journal entry exists (§10.1). |
| Paid, but the subscription is still `trialing` or `incomplete` | The webhook didn't arrive (§8.3), or the queue worker isn't running. Check the `webhook_logs` table in the landlord database, or use the fallback in §8.6. |
