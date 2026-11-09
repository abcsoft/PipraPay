# EPS live-test runbook (localhost → live API)

Status at the time of writing:

| Step | State |
|---|---|
| Local app, PHP 8.3, MariaDB | ready, verified over real HTTP |
| Provider DNS/TLS (`pgapi.eps.com.bd`) | reachable, TLS 1.3, valid certificate |
| EPS credentials in the local gateway | **missing** — blocked |
| Test brand switched to `custom` design | done (rollback below) |
| Public HTTPS callback | **not provisioned** — needs authorization |
| Live `GetToken` / `InitializeEPS` | blocked by the two items above |
| Real payment | not authorised |

Nothing in this document may be executed against production.

---

## 1. Enter the EPS credentials (operator action)

Admin → Gateways → EPS Gateway:

```
http://localhost/piprapayv3/admin/gateways/edit?ref=1
```

Fill exactly these fields (names as stored in `pp_gateways_parameter`):

| Field | Notes |
|---|---|
| `merchant_id` | EPS merchant id |
| `store_id` | EPS store id |
| `username` | EPS API username |
| `password` | EPS API password |
| `hashkey` | HMAC hash key |
| `mode` | must be **`live`** for the production API; empty/anything else silently targets sandbox |

Never paste these into chat, a commit, a log or a ticket. The save handler is
`gateway-setting-update` in `pp-content/pp-include/pp-adapter.php`.

Verify presence only (no values are printed):

```bash
php tests/…   # or any local read-only check that prints present=yes/no + length
```

## 2. Test brand payment design

Brand `1672919258` (the brand that owns the EPS gateway row `9370431764`) was
switched from `default` to `custom`:

```sql
-- before: default (pp_env id = 28)
UPDATE pp_env SET value = 'custom' WHERE id = 28;
```

Rollback:

```sql
UPDATE pp_env SET value = 'default', updated_date = NOW() WHERE id = 28;
```

Effect scope: the whole checkout UI of that brand (all gateways), local only.

## 3. Public HTTPS callback

`pp_callback_url()` builds the return URL from the **request Host**, so the
tunnel/host used to start the payment is what EPS will call back:

```
successUrl = https://<public-host>/piprapayv3/payment/<ref>?gateway=<gw>&category=cards&pp_callback
failUrl    = https://<public-host>/piprapayv3/payment/<ref>?manual=1
cancelUrl  = https://<public-host>/piprapayv3/payment/<ref>?manual=1
```

No code change is required. The payment must be **started through the public
host**, not through `localhost`, otherwise the baked callback points at
`http://localhost/...` and EPS cannot reach it.

Requirements the endpoint must satisfy:

| Requirement | Reason |
|---|---|
| Publicly resolvable DNS | EPS resolves it from its own network |
| Valid CA certificate (not self-signed) | provider-side TLS validation |
| No interactive auth / SSO / cookie wall | provider is not a browser session |
| No redirect on the callback path | a 301/302 loses `pp_callback` |
| Query string preserved verbatim | carries `gateway`, `category`, `pp_callback` |
| Only the application exposed | never expose `/admin`, `/.git`, `pp-config.php`, backups |

Candidate solutions (none activated):

1. **Cloudflare Tunnel with a dedicated hostname** — `cloudflared tunnel run`
   against a hostname you control; TLS terminates at Cloudflare; no inbound port.
2. **ngrok with a reserved domain** — same properties, free tier rotates hostnames
   which would break a whitelisted domain.
3. **Staging subdomain** on a public host that reverse-proxies to this machine —
   only useful if it is reachable and EPS whitelists the domain.

Activation requires your explicit authorisation; §8 (“Security”) of the task
forbids starting a tunnel unasked.

## 4. Verification sequence (after 1 + 3)

```bash
# a) token only – sanitized output (status, no token)
php <local script that calls EpsGateway::requestToken()>   # expects ok=true, http=200

# b) initialization with a throwaway transaction
curl -s -o NUL -D - "https://<public-host>/piprapayv3/payment/<ref>"
#   expect: 302 Location ...?gateway=<gw>&category=cards

curl -s "https://<public-host>/piprapayv3/payment/<ref>?gateway=<gw>&category=cards"
#   expect: 200 with <script>location.href="https://pg.eps.com.bd/PG?data=..."</script>
#   must NOT contain: data=null, data=undefined, "Payment could not be started"
```

Then open the returned hosted URL and check, without printing it:

- response status is 200 (no 504),
- the page shows the merchant name and the exact amount (e.g. `10.00`),
- no “invalid session / merchant” error is rendered.

## 5. Payment gate

Not permitted until the operator states **amount + currency** explicitly. Until
then the run stops after initialization; the transaction is left `initiated` and
the throwaway rows are deleted.

After an authorised payment:

1. provider status via `CheckMerchantTransactionStatus` (server-side),
2. `pp_transaction` moves `initiated → completed` exactly once,
3. repeat the callback twice → second must be a no-op,
4. refresh the checkout → no second `merchantTransactionId`,
5. `metadata.eps_checkout` must be absent after completion.

## 6. Rollback

```sql
UPDATE pp_env SET value = 'default', updated_date = NOW() WHERE id = 28;   -- design
DELETE FROM pp_gateways_parameter WHERE gateway_id = '9370431764';        -- credentials, if they must be removed
DELETE FROM pp_transaction WHERE ref LIKE 'EPS%TEST%';                     -- throwaway rows only
```

Stop the tunnel, confirm `pgapi.eps.com.bd` is no longer referenced by any local
transaction metadata, and verify the real gateway row is still `active`.

## 7. Unverified until the live run

- `x-hash` message for `GetToken` / `InitializeEPS` against the published specification.
- Whether EPS validates `successUrl` against a whitelisted domain.
- Provider session lifetime (local reuse window is 900 s).
- Source of the hosted-page 504 observed previously.

---

## 8. Incident triage — browser lands on `?data=null` in production

Reported symptom: hosted page opens at `https://pg.eps.com.bd/PG?data=null`,
"Invalid Request! Transaction Error", empty merchant id, payment methods not
loading, slow first paint.

Facts that constrain the diagnosis:

- `1e52b66` refuses to redirect unless the provider URL is HTTPS, on
  `eps.com.bd`, and carries a session token that is not `null` / `undefined` /
  empty / short / illegal. A `data=null` URL can therefore only reach the browser
  if (a) the deployed file is not `1e52b66`, or (b) the URL was valid at handoff
  and the provider rewrote it afterwards.
- `GET https://pg.eps.com.bd/PG?data=null` answers **200 in ~0.34 s** from this
  network, body contains `Invalid Request` / `Transaction Error`. It is a
  terminal EPS error page — no upstream 504 and no redirect chain.
- Nothing else on the gateway page navigates: the emitted
  `location.href=<provider URL>` is the only navigation for `gateway_type=api`.
  The AJAX `data.redirect` handler belongs to manual-verification gateways only.

### 8.1 Is the deployed file really `1e52b66`?

```bash
cd /home/pay.abcsoft.bd/public_html
git rev-parse HEAD
# must print: 1e52b66... (or a later reviewed commit)

git hash-object pp-content/pp-modules/pp-gateways/eps/class.php
# must print: c94729a9fb3930b45f2c8f3025a253bebcedb8fe   (1e52b66)

grep -c "EPS_INIT_NULL_SESSION" pp-content/pp-modules/pp-gateways/eps/class.php
# must be >= 1; 0 means the old gateway file is still in place
```

### 8.2 Is OPcache serving stale bytecode?

```bash
/usr/local/lsws/lsphp83/bin/php -i | grep -E "opcache.(enable|validate_timestamps|revalidate_freq)"
```

`opcache.validate_timestamps=0` (or a large `revalidate_freq`) keeps the old
class in memory across a deploy. Restart LSWS/PHP after deploying:

```bash
sudo systemctl restart lsws
```

### 8.3 What do the logs say?

```bash
grep "\[EPS\]" <error_log> | tail -20
```

| Build | Expected line on success | Expected line on rejection |
|---|---|---|
| `1e52b66` | `EPS_SESSION_CREATED \| ref=… mode=live` | `EPS_INIT_NULL_SESSION \| ref=… http=200 info=null_session` |
| next build (shape logging) | `… data_state=query_token data_len=NNN` | `… shape=scheme=https,host=pg.eps.com.bd,data=null_like,len=4` |

**No `[EPS]` line at the timestamp of the failed attempt means the old gateway
code executed** — every code path in the reviewed build logs exactly one line.

A repeated `EPS_SESSION_REUSED` on failing attempts means the cached hosted
session is being replayed; append `?retry=1` to the gateway URL to force a fresh
session before drawing conclusions about the provider.

### 8.4 Browser capture

DevTools → Network → enable **Preserve log** → reproduce → right-click → *Save
all as HAR*. Then, locally:

```bash
php tests/tools/analyze_eps_har.php capture.har
```

Verdicts:

| Verdict | Meaning |
|---|---|
| `PIPRAPAY` | the payment page document itself emitted the `data=null` URL → stale code (8.1/8.2) |
| `EPS` (redirect) | EPS answered a non-null URL with `Location: …data=null` → provider invalidated the session |
| `EPS` (script) | provider JavaScript/XHR navigated to `data=null` |
| `NO_NULL` | capture does not contain the symptom |

The analyzer prints only redacted URLs (`data=<redacted:NN>`), statuses, timings
and the initiating request. Keep the HAR itself local; it contains session
identifiers.

### 8.5 If the verdict is EPS

Then the handoff URL was valid and the provider rejected the session. Compare in
this order: `mode` (`live` vs `sandbox`), `merchantId`/`storeId` against the EPS
merchant portal, the `x-hash` message, and whether `successUrl` is reachable and
whitelisted. Escalate with §7 of `docs/eps-integration.md` (sanitized evidence
only).