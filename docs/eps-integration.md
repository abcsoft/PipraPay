# EPS Gateway Integration — Behaviour, Fixes and Deployment

Scope: PipraPay EPS (Easy Payment System, Bangladesh) hosted checkout, the
Cards-category gateway selection screen, and the failure/cancellation paths.

Branch: `fix/eps-checkout-hardening` (based on stable `038fd7f`).

---

## 1. What the integration does now

```
Customer opens /payment/{ref}
        │
        ├─ Cards category contains exactly one eligible gateway and it is EPS
        │  and no ?manual=1 / ?eps_error=... is present
        │        │
        │        └─ 302 → /payment/{ref}?gateway={id}&category=cards
        │                    │
        │                    └─ EPS gateway page → Auth/GetToken → EPSEngine/InitializeEPS
        │                                              │
        │                                              └─ hosted EPS checkout (https, EPS host, real session)
        │
        └─ otherwise → normal gateway selection screen (unchanged)
```

Return path:

```
EPS → successUrl (?pp_callback) → server-side CheckMerchantTransactionStatus
                                 → ValueA must equal the transaction ref
                                 → atomic status transition initiated → completed
EPS → failUrl / cancelUrl (manual=1) → selection screen, no automatic restart
```

### Automatic selection rules

Implemented in `pp_custom_auto_checkout_target()` (`pp-content/pp-include/pp-checkout-helpers.php`):

| Condition | Result |
|---|---|
| `cards` bucket has exactly 1 gateway with slug `eps` | auto-start |
| more than 1 eligible card gateway | normal selection |
| 0 eligible card gateways | normal selection |
| EPS inactive, or amount outside `min_allow`/`max_allow` | gateway not in the bucket → no auto-start |
| `?manual=1` | normal selection (documented opt-out) |
| `?eps_error=...` present | normal selection + banner (loop protection) |
| `pp_callback` present (provider return) | normal render; never redirected again |
| malformed `gateway_id` | refused |
| any other slug as the only card gateway | normal selection |

Automatic start is implemented in the `custom` payment design, which is the
design used in production. The `default` design and the built-in theme list
gateways by raw database tab and therefore keep their existing behaviour; they
would need the same hook before they could auto-start EPS.

The decision uses the same classification as the UI (`pp_custom_payment_category()`),
**not** the database `tab` column. EPS is stored with `tab=mfs` but is classified
as a Cards gateway because its slug matches neither the bank rule nor the known
mobile-financial-services slug list. This is the reason the earlier
`count($allGateways) === 1` check never fired: `$allGateways` contained gateways
from all three categories, so bKash Personal and Nagad Personal kept the count
above one.

---

## 2. Root-cause analysis

### A. Gateway selection screen required two clicks — reproduced, confirmed

- **Observed:** the custom checkout always rendered the gateway grid and the
  `Pay` button, whatever the number of eligible card gateways. No code path
  auto-selected a gateway.
- **Observed:** the previous attempt used `count($allGateways) === 1`, where
  `$allGateways` was the union of `pp_gateways('mfs')`, `pp_gateways('bank')`
  and `pp_gateways('global')`. With EPS + bKash Personal + Nagad Personal active
  the union can never have one element.
- **Reproduced:** the template harness renders the selection grid on the old
  behaviour and a redirect on the new one (`tests/test_eps_checkout_template.php`).
- **Confirmed:** removing the union and basing the decision on the *Cards* bucket
  makes a single-EPS checkout start without interaction.

### B. `https://pg.eps.com.bd/PG?data=null` — observed, mechanism identified

- **Observed (code):** the previous implementation only checked
  `empty($initData['RedirectURL'])`. A URL string containing `data=null` is not
  empty, so it was handed to the browser.
- **Observed (production):** the EPS page rendered without merchant id, amount
  or payment methods — consistent with a URL that carries no usable session.
- **Candidate:** the provider returns a `RedirectURL` even when session creation
  failed, embedding a `null` session placeholder; the integration had no
  response-shape validation to notice.
- **Now:** the URL is rejected unless it is `https`, hosted on `eps.com.bd` or a
  subdomain, free of credentials/whitespace/control characters, and carries a
  session token that is not empty, `null`, `undefined`, `none`, `nil`, `false`,
  `0`, shorter than 8 characters, or containing unexpected characters. The
  customer never reaches an invalid checkout page.
- **Not verified:** whether the provider would ever return a *valid* session for
  an otherwise valid payload — that needs live sandbox credentials.

### C. nginx `504 Gateway Time-out` on the EPS-hosted page — observed, provider-side, not reproduced

- **Observed (report):** a non-null session identifier was present in the URL and
  the EPS-hosted page itself answered `504 Gateway Time-out — nginx`.
- **Observed (code):** the integration performs two HTTPS round-trips and then
  hands the browser to the provider; it cannot influence the provider's nginx.
- **Conclusion:** the 504 is generated by EPS infrastructure (or by a session the
  provider considers invalid), not by PipraPay's PHP. Raising or lowering
  `CURLOPT_TIMEOUT` **cannot** repair an upstream nginx 504, and this change does
  not claim that it does.
- **What this change does:** distinguishes the three timeout layers, avoids
  duplicate session creation, and guarantees that a session is only used when it
  looks structurally valid.
- **Unverified:** whether the 504 correlates with a specific payload field
  (merchant/store id, `transactionTypeId`, amount, callback URL) — no sandbox
  access, therefore no counterfactual test. See §7 for the escalation checklist.

### D. Diagnostics — observed, fixed

The previous code had no timeout, no TLS enforcement, no HTTP status check, no
JSON validation and no distinction between "no token", "provider rejected" and
"transport failed". It also dereferenced `$resdata['ValueA']` without checking
that the key existed. Each failure is now a distinct, loggable code (§6).

---

## 3. Files changed

| File | Change |
|---|---|
| `pp-content/pp-modules/pp-gateways/eps/class.php` | Rewritten: strict transport, response and redirect validation, session reuse, safe verification, customer-facing fallback UI |
| `pp-content/pp-include/pp-checkout-helpers.php` | **New.** Pure helpers: category classification (moved from `pp-functions.php`), URL query merging, automatic-checkout decision, public error reasons/messages |
| `pp-content/pp-include/pp-functions.php` | Loads the helper library; adds `pp_gateway_meta_get()` / `pp_gateway_meta_set()`; `pp_custom_payment_category()` moved out (still available) |
| `pp-content/pp-payment-designs/custom/checkout.php` | Automatic EPS start, loop protection, error banner |
| `tests/test_eps_gateway.php` | **New.** 91 assertions, provider fully mocked |
| `tests/test_eps_checkout_template.php` + `tests/fixtures/checkout_template_harness.php` | **New.** 18 assertions rendering the real template |
| `docs/eps-integration.md` | This document |

**No database migration is required.** Session state is stored in the existing
`pp_transaction.metadata` JSON document under the `eps_checkout` key, written with
`SELECT … FOR UPDATE` inside a transaction.

---

## 4. Provider contract used

Base URLs:

| Mode | Base URL |
|---|---|
| `live` | `https://pgapi.eps.com.bd` |
| `sandbox` (default) | `https://sandboxpgapi.eps.com.bd` |

Only the literal mode `live` selects production; an empty or unknown mode falls
back to sandbox.

1. `POST /v1/Auth/GetToken`
   - `x-hash: base64(HMAC-SHA512(username, hashkey))`
   - body `{ "userName": ..., "password": ... }`
   - expects `{ "token": "..." }`
2. `POST /v1/EPSEngine/InitializeEPS`
   - `Authorization: Bearer <token>`
   - `x-hash: base64(HMAC-SHA512(merchantTransactionId, hashkey))`
   - body: `merchantId`, `storeId`, `CustomerOrderId`, `merchantTransactionId`,
     `transactionTypeId` (1 = web), `totalAmount`, `successUrl`, `failUrl`,
     `cancelUrl`, `customerName`, `customerEmail`, `customerAddress`,
     `customerCity`, `customerState`, `customerPostcode`, `customerCountry`,
     `customerPhone`, `productName`, `ValueA` (= PipraPay transaction ref)
   - expects `{ "RedirectURL": "..." }`
3. `GET /v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=…&EPSTransactionId=…`
   - `Authorization: Bearer <token>`, `x-hash` over the merchant transaction id
   - expects `Status`, `ValueA`, `MerchantTransactionId`, `EPSTransactionId`,
     optionally `FinancialEntity` and an amount field

### Known documentation gaps

The official EPS merchant documentation is not part of this repository and was
not reachable from the development environment. Consequently:

- The field set above is the one already used by this integration — nothing was
  invented, and nothing was copied from an unrelated provider.
- The body of `x-hash` for `InitializeEPS` (merchant transaction id) and the
  token request (username) are the existing conventions; they are **not**
  independently confirmed against a published specification.
- Amount formatting is sent as a plain decimal string with two decimals
  (`100.00`), no thousands separator.
- When the initialization response does not contain a `data` parameter, the
  redirect is accepted only if the path carries an opaque segment of 8+
  characters; otherwise it is treated as an unusable session.

If the provider confirms different semantics, adjust only
`EpsGateway::sign()`, `EpsGateway::requestToken()` or the payload builder — the
validation and fallback layers do not depend on them.

---

## 5. Safety behaviour

- **No redirect without a valid session.** `validateRedirectUrl()` rejects empty
  URLs, non-strings, `http://`, credentials in the URL, whitespace/control
  characters (header-injection guard), hosts outside `eps.com.bd`, and
  `data=null` / `data=undefined` / empty / too-short / illegal-charset tokens.
- **Refresh / back button.** A created session is cached for 900 seconds and
  re-used, so refreshing does not create a second merchant transaction. The
  explicit "Try again" button sets `?retry=1`, which forces a new session.
- **Concurrent first requests.** Two simultaneous first requests can still
  create two hosted sessions, because the cache is written after the provider
  answers. Only one of them can complete: completion is atomic and bound to the
  transaction reference, and every other callback is rejected.
- **No duplicate completion.** `pp_set_transaction_status()` performs an atomic
  `UPDATE … WHERE status = 'initiated'` and requires `rowCount() === 1`; a lost
  race is reported as `EPS_COMPLETION_REJECTED`, never as success. A callback for
  an already completed transaction is a no-op.
- **Server-side verification only.** The browser parameters are search keys; the
  decision comes from `CheckMerchantTransactionStatus`. `ValueA` must equal the
  transaction ref (`hash_equals`), the stored `merchantTransactionId` must match,
  and an optional reported amount must be within 0.01 of `local_net_amount`.
- **Customer data.** A missing/invalid email falls back to
  `noreply@<merchant-host>`; a missing phone number aborts with
  `EPS_CUSTOMER_INVALID` instead of creating a session that cannot complete.
- **No session data in merchant-visible metadata.** The hosted session is
  removed from `pp_transaction.metadata` immediately before the transaction is
  completed, because completion payloads echo metadata to merchant webhooks and
  API responses. During the short pre-payment window the `eps_checkout` key is
  still present in the row (visible to the merchant's own API calls); it holds
  no credentials, only the provider checkout URL, merchant transaction id and a
  timestamp.
- **Logging.** `error_log('[EPS] CODE | key=value …')` with values stripped to
  `[A-Za-z0-9_.:@/+-]`, truncated to 120 characters. Tokens, passwords, hash
  keys, signatures and full provider URLs are never logged.
- **Output.** All rendered values pass through `htmlspecialchars(..., ENT_QUOTES)`;
  the redirect uses `json_encode()` inside the script and `header()` where
  possible.
- **Customer-facing codes are coarse.** URLs only ever carry
  `configuration|amount|customer|unreachable|authentication|provider_response|rejected|session|verification|unknown`.
  Internal codes stay in the log.

---

## 6. Diagnostic codes

| Internal code | Meaning | Public reason |
|---|---|---|
| `EPS_CONFIG_INCOMPLETE` | merchant/store/user/pass/hash key missing | `configuration` |
| `EPS_AMOUNT_INVALID` | amount ≤ 0, non-numeric or above the hard ceiling | `amount` |
| `EPS_CUSTOMER_INVALID` | unusable customer phone | `customer` |
| `EPS_AUTH_TRANSPORT` | cURL failure on `GetToken` | `unreachable` |
| `EPS_AUTH_HTTP` | non-2xx on `GetToken` | `authentication` |
| `EPS_AUTH_INVALID_JSON` | unreadable token response | `provider_response` |
| `EPS_AUTH_NO_TOKEN` | 2xx without a usable `token` | `authentication` |
| `EPS_AUTH_UNAVAILABLE` | 429 or 5xx from `GetToken` (provider busy/outage) | `unavailable` |
| `EPS_INIT_TRANSPORT` | cURL failure on `InitializeEPS` | `unreachable` |
| `EPS_INIT_HTTP` | non-2xx on `InitializeEPS` | `provider_response` |
| `EPS_INIT_INVALID_JSON` | unreadable initialization response | `provider_response` |
| `EPS_INIT_REJECTED` | provider failure status in a 2xx body | `rejected` |
| `EPS_INIT_MISSING_URL` | no `RedirectURL` | `session` |
| `EPS_INIT_INSECURE_URL` | not `https` | `session` |
| `EPS_INIT_UNTRUSTED_HOST` | host outside `eps.com.bd`, or credentials in URL | `session` |
| `EPS_INIT_NULL_SESSION` | `data=null` / `undefined` / empty / no session parameter | `session` |
| `EPS_INIT_MALFORMED_SESSION` | too short / illegal characters / unparsable URL | `session` |
| `EPS_INIT_UNCERTAIN` | timeout or 5xx during `InitializeEPS`: the provider may have created the session | `uncertain` |
| `EPS_INIT_IN_PROGRESS` | another initialisation for the same transaction holds the claim | `in_progress` |
| `EPS_CALLBACK_INVALID` | callback without identifiers | `verification` |
| `EPS_CALLBACK_MISMATCH` | `ValueA` or merchant transaction id mismatch | `verification` |
| `EPS_CALLBACK_VERIFY_FAILED` | status endpoint transport/HTTP/JSON/status failure | `verification` |
| `EPS_CALLBACK_AMOUNT_MISMATCH` | verified amount differs from the transaction | `verification` |
| `EPS_COMPLETION_REJECTED` | atomic transition refused (already completed / not initiated) | `verification` |
| `EPS_TRANSACTION_STATE` | transaction is canceled/pending rather than initiated | `verification` |

---

## 7. Escalating the EPS-side 504

Send the provider only this, and never a full request/response dump:

1. Transaction reference (PipraPay `ref`) and local timestamp in UTC.
2. EPS `merchantTransactionId` and `EPSTransactionId`.
3. Mode (`live` / `sandbox`) and the API host used.
4. HTTP status and a 40-character excerpt of the *provider* status word, if any
   (`Status`, `ResponseCode`), from the server log line `[EPS] …`.
5. The fact that the hosted page itself returned
   `504 Gateway Time-out — nginx`, with the browser time and the URL host only.
6. Whether the same session fails repeatedly or only once.

Do **not** send: password, hash key, bearer token, `x-hash` value, customer
phone/email, or the full `RedirectURL` query string.

Because a piped request cannot fix an upstream gateway timeout, retries of
`InitializeEPS` are deliberately limited: automatic retry happens only on an
explicit customer action (`?retry=1`), never in a loop.

---

## 8. Tests

```bash
php tests/test_eps_gateway.php
php tests/test_eps_checkout_template.php
```

- 91 assertions: automatic-selection matrix, category regression, URL validation
  (`data=null`, `undefined`, empty, short, charset, untrusted host, look-alike
  host, `http://`, credentials, control characters), auth/init failures
  (transport, 4xx, 5xx, invalid JSON, provider rejection), amount/config/customer
  guards, successful init, refresh reuse, explicit retry, session expiry,
  verification (success, failure, spoofed redirect, `ValueA` mismatch, session
  mismatch, amount mismatch, transport/HTTP failure), duplicate callback
  idempotency, lost completion race, fallback page content, gateway contract,
  URL helper.
- 18 assertions: the real `custom/checkout.php` template renders a redirect for a
  single EPS card gateway, still renders the grid for `?manual=1`, shows a safe
  banner for `?eps_error=session`, and keeps bKash/Nagad selectable.
- Both suites are database-free and network-free. Provider HTTP is stubbed; no
  real payment is ever attempted.

Existing suites (`tests/test_guard.php`, `tests/test_custom_personal_payment.php`,
`tests/test_api_compatibility_and_security.php`) require a dedicated database
whose name contains `test` and `PIPRAPAY_TEST_MODE=1`; they were not executed in
this change because no test database is provisioned in this environment.

### What is *not* verified

- Live sandbox initialisation and a real hosted checkout (needs merchant
  credentials and provider permission).
- Whether the 504 disappears once a structurally valid session is used.
- Any end-to-end payment. No claim of a successful real transaction is made.

---

## 9. Deployment (prepared, not executed)

Production: `/home/pay.abcsoft.bd/public_html`, PHP `/usr/local/lsws/lsphp83/bin/php`,
stable commit `038fd7fe77bea613e6a6fd5353f0fa0dc19e2999`.

```bash
# 0. Backup first (database + files), keep the artefacts off the web root.
ssh pay.abcsoft.bd
cd /home/pay.abcsoft.bd
tar czf ~/backup_public_html_$(date +%F_%H%M).tar.gz public_html
mysqldump --single-transaction --routines <db> > ~/backup_db_$(date +%F_%H%M).sql

# 1. Fetch the reviewed branch (never force-push, never reset --hard).
cd public_html
# Prerequisite: the branch must have been pushed to origin first (it is local only
# at the time of writing). Then:
git fetch origin fix/eps-checkout-hardening
git checkout fix/eps-checkout-hardening

# 2. Syntax check the changed files with the production binary.
/usr/local/lsws/lsphp83/bin/php -l pp-content/pp-modules/pp-gateways/eps/class.php
/usr/local/lsws/lsphp83/bin/php -l pp-content/pp-include/pp-checkout-helpers.php
/usr/local/lsws/lsphp83/bin/php -l pp-content/pp-include/pp-functions.php
/usr/local/lsws/lsphp83/bin/php -l pp-content/pp-payment-designs/custom/checkout.php

# 3. Restart PHP for OpenLiteSpeed only if opcache/preloading requires it.
sudo systemctl restart lsws
```

No configuration change and no migration are required. The EPS gateway settings
(`merchant_id`, `store_id`, `username`, `password`, `hashkey`, `mode`) are read
from the existing gateway parameters.

### Smoke-test checklist

1. `GET /payment/{ref}` with EPS as the only card gateway → immediate redirect to
   `?gateway=…&category=cards`, then the EPS-hosted page. No grid, no Pay click.
2. Add a second active card gateway → grid returns and the customer chooses.
3. `GET /payment/{ref}?manual=1` → grid, EPS selectable.
4. Deactivate EPS → checkout falls back to bKash/Nagad without errors.
5. Confirm the server log contains `[EPS] EPS_SESSION_CREATED` (no secrets).
6. Complete a low-value sandbox payment, confirm `completed` and the EPS
   transaction id in the transaction details.
7. Refresh the payment page mid-flow → same hosted session, no second
   `merchantTransactionId`.

### Rollback

```bash
cd /home/pay.abcsoft.bd/public_html
git checkout 038fd7fe77bea613e6a6fd5353f0fa0dc19e2999
sudo systemctl restart lsws
```

If only the EPS gateway misbehaves, disable the gateway in the admin panel — no
code change is needed.

The new files are untracked by the stable commit, so `git checkout` leaves them on
disk. They are inert afterwards, but if you want them gone, remove them **only
after** the checked-out `pp-functions.php` no longer requires the helper:

```bash
# 1) revert first
git checkout 038fd7fe77bea613e6a6fd5353f0fa0dc19e2999
grep -c "pp-checkout-helpers" pp-content/pp-include/pp-functions.php   # must print 0
# 2) only then remove the untracked additions
rm -f pp-content/pp-include/pp-checkout-helpers.php
rm -rf tests/fixtures tests/test_eps_gateway.php tests/test_eps_checkout_template.php
rm -f docs/eps-integration.md
```

Deleting `pp-checkout-helpers.php` while `pp-functions.php` still requires it
would fatal every request: keep the order above.

`pp_transaction.metadata` values written by this integration are additive JSON
keys (`eps_checkout`); leaving them in place is harmless after a rollback.

---

## 10. Embedded checkout

Automatic redirect to the EPS-hosted page is the supported behaviour. Inline
iframe embedding is **not** enabled: it needs written merchant permission, an
iframe-compatible provider page, verified 3-D Secure behaviour and a relaxed
CSP/frame policy. The integration never bypasses EPS `CSP` or `X-Frame-Options`.

---

## 11. Unresolved provider dependencies

1. Valid merchant credentials in sandbox are required to prove session creation.
2. The published `x-hash` specification must be confirmed by EPS; the current
   implementation keeps the existing conventions.
3. The source of the hosted-page 504 is provider-side and unconfirmed.
4. Callback parameter names (`MerchantTransactionId`, `EPSTransactionId`) are
   taken from the existing integration and are not re-verified against a spec.

---

## 12. Retry, timeout and concurrency policy

### GetToken

| Setting | Value |
|---|---|
| Attempts | 2 (the second only for transient failures) |
| Per-attempt timeout | `min(30 s, remaining budget)` |
| Wall-clock budget | 35 s |
| Backoff | 400 ms + 0–300 ms jitter; `Retry-After` clamped to 0.2–5 s |

Retried: curl timeout/DNS/connect/TLS, HTTP 429, HTTP 5xx.
Not retried: HTTP 400/401/403, a 2xx without a usable token, malformed JSON.

Worst case ≈ 36 s (30 s first attempt + ≤0.7 s backoff + ≤5 s second attempt).
The last attempt's timeout shrinks to whatever budget remains, so the loop cannot
run longer than the budget plus one backoff.

### InitializeEPS

Exactly one attempt, 30 s. It is **never** retried automatically: a timeout may
hide a session the provider already created. The outcome is recorded as
`uncertain`, the customer sees a "could not confirm" screen with an explicit
"Try again", and nothing is ever marked completed from an uncertain state.

### Session state machine (`pp_transaction.metadata.eps_checkout`)

| State | Meaning | Reused? |
|---|---|---|
| `ready` | validated hosted URL | yes, within 900 s |
| `pending` | initialisation in flight | no |
| `uncertain` | provider may have created a session | no |
| `failed` | definite failure | no |

Legacy records without `state` are treated as `ready` (they were only written
after a full success).

### Concurrency

`pp_gateway_meta_claim()` performs `SELECT … FOR UPDATE` inside a transaction and
stores a `claimed_at` timestamp under `eps_init_lock` (TTL 90 s). A second request
for the same transaction — double click, reload, second tab — gets
`EPS_INIT_IN_PROGRESS` **before any provider call**. The claim is released in a
`finally` block; the loser never releases a claim it did not take. An expired
claim may be taken over.

### Worst-case customer wait

`36 s (token) + 30 s (initialisation) ≈ 66 s`, during which the loading indicator
is already painted (output is flushed before the first provider call). No path
leaves the spinner spinning: every branch ends in a redirect or an error screen.