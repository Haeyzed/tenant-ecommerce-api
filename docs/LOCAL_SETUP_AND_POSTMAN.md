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

## 10. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `503 registration_unavailable` | The Terms or Privacy Policy is not published (§4.2), or `tenant_registration_enabled` is `false`. |
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
| Paid, but the subscription is still `trialing` or `incomplete` | The webhook didn't arrive (§8.3), or the queue worker isn't running. Check the `webhook_logs` table in the landlord database, or use the fallback in §8.6. |
