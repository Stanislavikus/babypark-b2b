# Task 4B-2a-1 Phase A — OAuth1 Signer Research and Decision

- **Status:** Phase A research complete — awaiting human approval before Phase B
- **Created:** 2026-07-22
- **Applies to:** Task 4B-2a connector foundation (credentials, PaaS adapter signing)
- **Source of truth:** this file is not a Resolved project document and must not
  override `03-DOMAIN_MODEL.md`, `04-ARCHITECTURE_PRINCIPLES.md`,
  `05-AI_WORKING_AGREEMENT.md`, or `07-TECH_STACK.md`.
- **Application code status:** blocked until this recommendation is approved;
  Phase B (port, adapter shell, signer implementation) must not start without
  explicit approval.

---

## Evidence legend

| Label | Meaning |
|---|---|
| **officially documented fact** | Stated in a primary vendor/protocol/framework source cited below |
| **repository fact** | Confirmed by reading this repository in the 4B-2a-1 Phase A session |
| **reasoned recommendation** | Proposed design choice with options and rationale |
| **open option requiring approval** | Genuinely unresolved; human must choose |

---

## Documentation consistency check (pre-research gate)

**repository fact:** After branching from `origin/develop` at
`f5dcf0cf26f30b87843bfdd4417fb0d7fa2dd899` (verified as ancestor), the
following heading markers were read directly in `docs/03-DOMAIN_MODEL.md`:

| Section | Expected (per task brief) | Actual on `develop` | Status |
|---|---|---|---|
| Connector adapter capabilities | `(Resolved)` | `### Connector adapter capabilities (proposed)` | **Inconsistent** |
| Credential and settings classification | `(Resolved)` | `#### Credential and settings classification (proposed)` | **Inconsistent** |
| ConnectorAccount authorization | `(Resolved)` | `### ConnectorAccount authorization (Resolved)` | Consistent |

**repository fact:** `docs/07-TECH_STACK.md` carries
`## Connector runtime (Resolved — Task 4B-2-0)` and
`### Adobe PaaS OAuth 1.0a signing (Resolved)` — consistent with Task 4B-2-0
promotion for the tech-stack sections this task depends on.

**reasoned recommendation:** This Phase A proposal treats
`07-TECH_STACK.md`'s Adobe signing section and the `(Resolved)` connector
authorization/error-mapping blocks in `03-DOMAIN_MODEL.md` as normative. The
two still-`(proposed)` domain-model subsections are cited as **proposed-only**
context, not as promoted normative authority. This research task does **not**
rename or patch those headings (out of scope for Phase A).

---

## 1. Current dependency graph (repository fact)

Pinned versions read fresh from `composer.lock` on branch
`cursor/task-4b2a-1-connector-foundation-credentials`:

| Package | Locked version | `07-TECH_STACK.md` record | Match? |
|---|---|---|---|
| `psr/http-message` | **2.0** | `psr/http-message 2.0` | Yes |
| `guzzlehttp/psr7` | **2.10.4** | `guzzlehttp/psr7 2.10.4` | Yes |
| `guzzlehttp/guzzle` | **7.10.6** | *(not recorded in 07-TECH_STACK)* | — |

**repository fact:** `composer.json` requires `php: ^8.2`. No OAuth 1.0 signing
library is present in the current dependency graph.

**repository fact:** `07-TECH_STACK.md` already documents the
`api-clients/psr7-oauth1` exclusion due to `psr/http-message ^1.0.1` vs project
`2.0` — confirmed still accurate (see §3).

---

## 2. MVP scope: protected-resource request signing only

**officially documented fact:** Adobe Commerce PaaS integration authentication
uses OAuth 1.0a with `HMAC-SHA256` as the signature method (Adobe,
*OAuth-based authentication*, accessed 2026-07-22,
https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth).

**officially documented fact:** For **self-activated** integrations, Adobe
hands over all four credential values directly after Admin activation — consumer
key, consumer secret, access token, and access token secret — and explicitly
states that callers **do not need** `/oauth/token/request` or
`/oauth/token/access` (Adobe, *Authentication overview*, accessed 2026-07-22,
https://developer.adobe.com/commerce/webapi/get-started/authentication/).

**officially documented fact:** Experience League confirms: "After setting up an
integration and receiving the credentials, it is no longer necessary to make calls
to access or request tokens." (Adobe, *Integrations*, accessed 2026-07-22,
https://experienceleague.adobe.com/en/docs/commerce-admin/systems/integrations)

Phase A scope is therefore **protected-resource request signing only**:

| In scope | Out of scope |
|---|---|
| Sign outbound REST calls (`GET`, `POST`, …) with pre-provisioned four-part credentials | Request-token exchange |
| Build `Authorization: OAuth …` header | Access-token exchange |
| RFC 5849 parameter normalization + Adobe `HMAC-SHA256` | Callback / browser authorization flows |
| Deterministic fixture tests | OAuth storage engines, provider/user-profile abstractions |

**reasoned recommendation:** Libraries whose primary value is the full OAuth
authorization handshake are penalized, not rewarded, unless their signing
component can be cleanly isolated behind `OAuth1RequestSigner` without dragging
unused HTTP-client frameworks or handshake abstractions into the connector
runtime.

---

## 3. Maintained PHP OAuth1 signing libraries — candidate survey

### Search methodology

**repository fact:** Candidates were discovered via Packagist search, GitHub
code search, cross-references from Task 4B-2-0 proposal B5, and Adobe/Magento
ecosystem conventions. For each serious candidate:

1. Inspected the **released tag source** for `HMAC-SHA256` (not assumed).
2. Ran Composer dry-run against the **current lockfile**:
   ```bash
   composer require "<vendor/package>:<constraint>" \
     --dry-run --no-scripts --no-plugins --no-interaction
   git diff --exit-code -- composer.json composer.lock
   ```
3. Recorded license, maintenance signals, and security advisories from Packagist.

Dry-run contract: `--no-scripts` **and** `--no-plugins` per Composer FAQ on
plugin execution (https://getcomposer.org/doc/faqs/plugins.md).

---

### 3.1 Already excluded — `api-clients/psr7-oauth1`

| Field | Value |
|---|---|
| Constraint tested | `api-clients/psr7-oauth1:^3.0` |
| Tag inspected | `3.0.0` (2021-05-13) |
| HMAC-SHA256 | **Yes** — `HmacSha256Signature` class (https://github.com/php-api-clients/psr7-oauth1/blob/3.0.0/src/Signature/HmacSha256Signature.php) |
| Composer dry-run | **FAIL** (exit 2) |
| Literal solver output | `api-clients/psr7-oauth1 3.0.0 requires psr/http-message ^1.0.1 -> found psr/http-message[1.0.1, 1.1] but the package is fixed to 2.0 (lock file version)` |
| `git diff` after dry-run | clean |
| Status | **Disqualified** — prior finding confirmed; must not downgrade PSR-7 stack |

---

### 3.2 `horde/oauth` ^4.0

| Field | Value |
|---|---|
| Constraint tested | `horde/oauth:^4.0` |
| Tag inspected | `v4.0.0` (2026-06-30) |
| Packagist | https://packagist.org/packages/horde/oauth |
| License | BSD-2-Clause |
| HMAC-SHA256 | **Yes** — `Horde\OAuth\V10a\Signature\HmacSha256` uses `hash_hmac('sha256', …, true)` + `base64_encode` (https://github.com/horde/Oauth/blob/v4.0.0/src/V10a/Signature/HmacSha256.php); covered by `HmacSha256Test` (https://github.com/horde/Oauth/blob/v4.0.0/test/V10a/Signature/HmacSha256Test.php) |
| PHP compatibility | `^8.1` — compatible with project `^8.2` |
| Extensions | `ext-hash`, `ext-json`, `ext-openssl` (RSA path only; still a hard require) |
| Transitive deps added | `horde/jwt`, `psr/http-server-handler`, `psr/http-server-middleware` |
| PSR-7 | `psr/http-message: ^2` — compatible |
| Composer dry-run | **PASS** (exit 0) — locks `horde/oauth v4.0.0` + 3 transitive packages |
| `git diff` after dry-run | clean |
| Maintenance | Active — `v4.0.0` released 2026-06-30; 849 GitHub stars (Packagist, 2026-07-22) |
| Security advisories | None on Packagist for this package (checked 2026-07-22) |
| Isolatable signing? | **Partially** — `SignedRequest` + `HmacSha256` + `AuthorizationHeader` are transport-independent and usable without `OAuth1Client` or token-exchange flows. `SignedRequest` includes RFC 5849 base-string logic with duplicate-key array support and RFC 3986 encoding (https://github.com/horde/Oauth/blob/v4.0.0/src/V10a/Request/SignedRequest.php). |
| Signing gaps for Adobe | `AuthenticatedHttpClient` does **not** merge URL query parameters or `application/x-www-form-urlencoded` body fields into the signed parameter set — only OAuth protocol params (https://github.com/horde/Oauth/blob/v4.0.0/src/V10a/Client/AuthenticatedHttpClient.php). Adobe connection-check URL includes `searchCriteria[pageSize]=1` query params that **must** be signed. A project wrapper is mandatory regardless. |
| Scope penalty | Full OAuth 1.0a **client**, OAuth 2.0, and OpenID Connect library — far beyond protected-resource signing. |

---

### 3.3 `league/oauth1-client` ^1.10

| Field | Value |
|---|---|
| Constraint tested | `league/oauth1-client:^1.10` |
| Tag inspected | `v1.11.0` |
| Packagist | https://packagist.org/packages/league/oauth1-client |
| License | MIT |
| HMAC-SHA256 | **No** — signature classes are `HmacSha1Signature`, `RsaSha1Signature`, `PlainTextSignature` only (https://github.com/thephpleague/oauth1-client/tree/v1.11.0/src/Signature) |
| Composer dry-run | **PASS** (exit 0) — locks `v1.11.0` |
| `git diff` after dry-run | clean |
| Maintenance | Last release 2022; widely used but SHA256 absent |
| Status | **Disqualified** — Adobe requires `HMAC-SHA256` |

---

### 3.4 `guzzlehttp/oauth-subscriber`

| Field | Value |
|---|---|
| Constraints tested | `^0.6` (blocked by advisory), `^0.9.4` (partial update), `^0.9.4` with `-W` |
| Tag inspected | `0.9.5` |
| Packagist | https://packagist.org/packages/guzzlehttp/oauth-subscriber |
| HMAC-SHA256 | **Yes** — `SIGNATURE_METHOD_HMACSHA256 = 'HMAC-SHA256'` (https://github.com/guzzle/oauth-subscriber/blob/0.9.5/src/Oauth1.php) |
| `^0.6` dry-run | **FAIL** (exit 2) — blocked by security advisory `PKSA-pg71-gz29-h5sq` |
| `^0.9.4` dry-run (no `-W`) | **FAIL** (exit 2) — `requires guzzlehttp/guzzle ^7.13.3` but lock pins `7.10.6` |
| `^0.9.4` dry-run (`-W`) | **PASS** but upgrades `guzzlehttp/guzzle 7.10.6 → 7.15.1`, `guzzlehttp/psr7 2.10.4 → 2.13.0`, `guzzlehttp/promises 2.4.1 → 2.5.1`, `symfony/deprecation-contracts v3.7.0 → v3.7.1` |
| `git diff` after dry-run | clean |
| HTTP framework coupling | Guzzle `HandlerStack` middleware — couples signing to Guzzle transport pipeline |
| Status | **Disqualified** — requires HTTP-stack version churn; Guzzle-middleware shape conflicts with the approved signer/transport separation (sign on logical request, pin IP afterward via `CURLOPT_RESOLVE`) |

---

### 3.5 `socialconnect/auth` ^3.3

| Field | Value |
|---|---|
| Constraint tested | `socialconnect/auth:^3.3` |
| Tag inspected | `3.6.2` |
| Packagist | https://packagist.org/packages/socialconnect/auth |
| HMAC-SHA256 | **No** — OAuth1 signatures are `MethodHMACSHA1` and `MethodRSASHA1` only (https://github.com/SocialConnect/auth/tree/3.6.2/src/OAuth1/Signature) |
| Composer dry-run | **PASS** (exit 0) — adds `socialconnect/jwx` |
| Status | **Disqualified** — full multi-protocol auth library without SHA256 |

---

### 3.6 `mastercard/oauth1-signer` ^1.1

| Field | Value |
|---|---|
| Constraint tested | `mastercard/oauth1-signer:^1.1` |
| Tag inspected | `v1.1.4` |
| Packagist | https://packagist.org/packages/mastercard/oauth1-signer |
| HMAC-SHA256 | **No** — uses `RSA-SHA256` + `oauth_body_hash` extension (https://github.com/Mastercard/oauth1-signer-php/blob/v1.1.4/src/Developer/OAuth/OAuth.php) |
| Composer dry-run | **PASS** (exit 0) |
| Abandoned | **Yes** — Composer reports "Package mastercard/oauth1-signer is abandoned" |
| Status | **Disqualified** — wrong algorithm family, abandoned |

---

### 3.7 `michaeldrennen/oauth1-signature` dev-master

| Field | Value |
|---|---|
| Constraint tested | `michaeldrennen/oauth1-signature:dev-master` |
| Commit inspected | `2648f0c` |
| Packagist | https://packagist.org/packages/michaeldrennen/oauth1-signature |
| HMAC-SHA256 | **No** — hardcoded `hash_hmac("sha1", …)` (https://github.com/michaeldrennen/oauth1-signature/blob/2648f0cd61547263322416dd5b0af88413e031ef/src/OAuth1Signature.php) |
| Encoding | Uses `urlencode()` not RFC 3986 `rawurlencode()` — `%20` vs `+` risk |
| Duplicate params | Associative-array model; duplicate handling incomplete per author `@todo` |
| Composer dry-run | **PASS** (exit 0) |
| Installs | ~16 total (Packagist, 2026-07-22) |
| Status | **Disqualified** — SHA1 only, weak maintenance, incorrect encoding |

---

### 3.8 Candidate summary table

| Package | Solver (current lock) | HMAC-SHA256 | Appropriately scoped | Verdict |
|---|---|---|---|---|
| `api-clients/psr7-oauth1` | FAIL (PSR-7 v1) | Yes | Yes (signer-only) | Excluded |
| `horde/oauth` | PASS | Yes | **No** (OAuth 2/OIDC client) | Viable but over-scoped |
| `league/oauth1-client` | PASS | **No** | No (full client) | Disqualified |
| `guzzlehttp/oauth-subscriber` | FAIL / requires Guzzle upgrades | Yes | No (Guzzle middleware) | Disqualified |
| `socialconnect/auth` | PASS | **No** | No (full auth) | Disqualified |
| `mastercard/oauth1-signer` | PASS | **No** (RSA) | Partial | Disqualified |
| `michaeldrennen/oauth1-signature` | PASS | **No** | Partial | Disqualified |

**reasoned recommendation:** No candidate simultaneously satisfies (a) clean
solver against the current lockfile without HTTP-stack churn, (b) confirmed
`HMAC-SHA256` in released source, and (c) appropriately-scoped, signing-only
surface that can be adopted without a full OAuth client framework. `horde/oauth`
is the only SHA256-capable package that installs cleanly, but it fails the
scope criterion and still requires a custom parameter-merging wrapper for Adobe
query strings.

---

## 4. Isolated project-owned signer — concrete design sketch

Whether or not a library is adopted, `07-TECH_STACK.md` already names the port:

`App\Support\Connectors\OAuth1\OAuth1RequestSigner`

Phase B implements this sketch behind that port.

### 4.1 RFC 5849 vs Adobe algorithm — evidence distinction

| Topic | Source | Fact |
|---|---|---|
| Parameter collection, normalization, base-string URI, signature base string, `Authorization` header mechanics | RFC 5849 §3.4 (https://datatracker.ietf.org/doc/html/rfc5849#section-3.4) | Defines OAuth 1.0 request signing **mechanics** |
| Standard HMAC signature method | RFC 5849 §3.4.2 | Documents **`HMAC-SHA1`**, not SHA256 |
| Adobe signature method | Adobe OAuth docs | Requires **`HMAC-SHA256`** substitution on top of RFC 5849 mechanics |
| PHP HMAC primitive | PHP `hash_hmac()` (https://www.php.net/manual/en/function.hash-hmac.php) | 4th parameter `true` = raw binary output before `base64_encode` |
| Nonce entropy | PHP `random_bytes()` (https://www.php.net/manual/en/function.random-bytes.php) | CSPRNG for production nonce generation |

The isolated signer reuses RFC 5849 signing mechanics while substituting Adobe's
required `HMAC-SHA256` algorithm in place of RFC 5849's documented `HMAC-SHA1`
method.

### 4.2 Algorithm steps (mapped to RFC 5849)

| Step | RFC section | Implementation |
|---|---|---|
| 1. Uppercase HTTP method | §3.4.1.1 | `strtoupper($method)` |
| 2. Base-string URI normalization | §3.4.1.2 | Lowercase scheme + host; omit default port (80/443); include path; **exclude** query string and fragment from base-string URI |
| 3. Parameter collection | §3.4.1.3 | Collect: OAuth protocol params except `oauth_signature`; all query params; form-body params **only** when `Content-Type` is `application/x-www-form-urlencoded`; **exclude** JSON body fields |
| 4. Duplicate parameter names | §3.4.1.3.1 | Use `list<array{name: string, value: string}>` (or equivalent), **not** a plain PHP associative array |
| 5. RFC 3986 percent-encoding | §3.6 | `rawurlencode()` on names and values (`%20`, never `+`) |
| 6. Sort parameters | §3.4.1.3.2 | Sort by encoded name, then encoded value |
| 7. Normalized parameter string | §3.4.1.3.2 | Join sorted `name=value` pairs with `&` |
| 8. Signature base string | §3.4.1.1 | `METHOD & baseStringUri & normalizedParams`, each component RFC 3986-encoded, joined by `&` |
| 9. Signing key | §3.4.2 | `percentEncode(consumerSecret) + "&" + percentEncode(tokenSecret)` |
| 10. HMAC digest | Adobe + PHP docs | `base64_encode(hash_hmac('sha256', $baseString, $signingKey, true))` |
| 11. `Authorization` header | §3.5.1 | `OAuth ` + comma-separated `name="value"` pairs for OAuth protocol params only |

### 4.3 Adobe protected-resource `Authorization` header parameter set

**officially documented fact** — Adobe *Access the web APIs* lists these
`Authorization` header fields for signed REST calls (accessed 2026-07-22,
https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth):

| Parameter | Value | In `Authorization` header | In signed parameter set |
|---|---|---|---|
| `oauth_consumer_key` | consumer key | Yes | Yes |
| `oauth_token` | access token | Yes | Yes |
| `oauth_nonce` | per-request random | Yes | Yes |
| `oauth_timestamp` | Unix seconds UTC | Yes | Yes |
| `oauth_signature_method` | `HMAC-SHA256` | Yes | Yes |
| `oauth_signature` | computed | Yes | **No** (excluded per RFC 5849 §3.4.1.3) |
| `oauth_version` | `1.0` | See below | Yes (if emitted) |
| `realm` | — | **No** | **No** (RFC 5849 §3.5.1 — optional, excluded from signature if present) |

**`oauth_version` resolution:**

- Adobe token-exchange endpoint tables **require** `oauth_version` (same source).
- Adobe *The OAuth signature* section lists `oauth_version` among attributes
  concatenated into the signature base string (same source).
- Adobe *Access the web APIs* protected-resource header list **omits**
  `oauth_version`.
- **reasoned recommendation:** Emit `oauth_version=1.0` in **both** the signed
  parameter set and the `Authorization` header. Rationale: included in Adobe's
  signature description and token flows; matches `Horde\OAuth\V10a\Client\
  AuthenticatedHttpClient` behavior; RFC 5849 treats it as a valid OAuth
  protocol parameter. Phase B fixture tests against a live Adobe sandbox remain
  the final verifier — if Adobe rejects `oauth_version` in the header, drop it
  from the header only while keeping it in the signed set (unlikely based on
  Magento ecosystem precedent).

Request-specific query and form parameters participate in the signature base
string when applicable but are **not** duplicated into the `Authorization`
header.

### 4.4 Signer / transport boundary (B13 SSRF compatibility)

**officially documented fact:** `CURLOPT_RESOLVE` populates libcurl's DNS cache
for a `HOST:PORT` pair so connections use a supplied IP while the request URL
hostname is unchanged (libcurl, *CURLOPT_RESOLVE*, accessed 2026-07-22,
https://curl.se/libcurl/c/CURLOPT_RESOLVE.html): "This option effectively
populates the DNS cache with entries for the host+port pair."

**repository fact:** Task 4B-2-0 B13 contract (promoted to `07-TECH_STACK.md`)
requires signing on the **original logical hostname/URL**, then applying
`CURLOPT_RESOLVE` only at transport time.

**reasoned recommendation:** `OAuth1RequestSigner` accepts the logical request
URI (including path and query string for parameter extraction) and returns a
signed `Authorization` header (or signed header map). The SSRF-safe transport
applies `CURLOPT_RESOLVE` **after** signing. The signer must **not**:

- replace the request URI host with the resolved IP in the base-string URI;
- alter the `Host` header;
- break TLS SNI or certificate hostname verification.

Any library wrapper (including `horde/oauth`'s `SignedRequest`) must receive
the merchant's canonical `base_url` hostname, not the pinned IP.

### 4.5 Clock / nonce injection boundary

```php
// Sketch only — Phase B implements behind OAuth1RequestSigner port
final readonly class OAuth1SigningContext
{
    public function __construct(
        public string $nonce,
        public int $timestamp,
    ) {}
}

interface OAuth1RequestSigner
{
    public function sign(
        string $method,
        string $requestUri,          // logical URL incl. query string
        ?string $formBody,           // null unless application/x-www-form-urlencoded
        OAuth1Credentials $credentials,
        OAuth1SigningContext $context,
    ): string;                       // full Authorization header value
}
```

Production wiring injects `OAuth1SigningContext` from `bin2hex(random_bytes(16))`
and `time()`. Tests inject fixed values — the core algorithm never calls
`time()` or `random_bytes()` internally.

### 4.6 Proposed module layout and complexity estimate

| Unit | Responsibility | Approx. LOC |
|---|---|---|
| `OAuth1ParameterBag` | Duplicate-safe name/value collection | ~40 |
| `OAuth1PercentEncoder` | RFC 3986 encode | ~15 |
| `OAuth1BaseStringUri` | §3.4.1.2 normalization | ~35 |
| `OAuth1SignatureBaseString` | §3.4.1.1 assembly | ~30 |
| `OAuth1AuthorizationHeader` | §3.5.1 header formatting | ~25 |
| `OAuth1RequestSigner` (impl) | Orchestration | ~50 |
| **Production total** | | **~195 LOC** |
| `OAuth1RequestSignerTest` | Fixture vectors (§4.7) | ~250 LOC |
| **Test total** | | **~250 LOC** |

~6 pure/testable functions + 1 orchestrator. No interfaces beyond the port
already named in `07-TECH_STACK.md`.

### 4.7 Required fixture set (Phase B tests)

| # | Fixture case | Asserts |
|---|---|---|
| F1 | Percent-encoding: space, `+`, `~`, `%`, Unicode (e.g. `київ`) | RFC 3986 output (`%20` not `+`; `~` unescaped) |
| F2 | Duplicate query names (`foo=1&foo=2`) | Both pairs in normalized string |
| F3 | Empty parameter value (`empty=`) | Preserved in normalized string |
| F4 | Default port omission (`https://host:443/path`) | Base-string URI excludes `:443` |
| F5 | Non-default port (`https://host:8443/path`) | Base-string URI includes `:8443` |
| F6 | Query param in normalized string but excluded from base-string URI | Same request — path-only URI vs param string contains `searchCriteria[…]` |
| F7 | JSON request body | Body fields **not** in parameter set |
| F8 | `application/x-www-form-urlencoded` body | Body fields **in** parameter set |
| F9 | Exact normalized parameter string | Golden string match |
| F10 | Exact signature base string | Golden string match |
| F11 | Exact `HMAC-SHA256` signature (base64) | Golden signature match |
| F12 | `Authorization` header | Parse header → exact parameter map + signature value (canonical **project** parameter ordering documented in test, not claimed as protocol-mandated) |

#### Representative golden vector (F9–F12)

Fixed inputs for reproducibility:

| Input | Value |
|---|---|
| Method | `GET` |
| URL | `https://shop.example.com/rest/default/V1/products/attributes?searchCriteria[pageSize]=1` |
| Body | `null` |
| `oauth_consumer_key` | `ck_test` |
| `oauth_token` | `at_test` |
| Consumer secret | `cs_test` |
| Token secret | `ts_test` |
| `oauth_nonce` | `abc123nonce` |
| `oauth_timestamp` | `1700000000` |
| `oauth_version` | `1.0` |

**repository fact** — computed in Phase A research session with RFC 5849 +
Adobe `HMAC-SHA256` rules:

**Normalized parameter string (F9):**
```
oauth_consumer_key=ck_test&oauth_nonce=abc123nonce&oauth_signature_method=HMAC-SHA256&oauth_timestamp=1700000000&oauth_token=at_test&oauth_version=1.0&searchCriteria%5BpageSize%5D=1
```

**Signature base string (F10):**
```
GET&https%3A%2F%2Fshop.example.com%2Frest%2Fdefault%2FV1%2Fproducts%2Fattributes&oauth_consumer_key%3Dck_test%26oauth_nonce%3Dabc123nonce%26oauth_signature_method%3DHMAC-SHA256%26oauth_timestamp%3D1700000000%26oauth_token%3Dat_test%26oauth_version%3D1.0%26searchCriteria%255BpageSize%255D%3D1
```

**Signing key:** `cs_test&ts_test`

**HMAC-SHA256 signature, base64 (F11):** `PhzkFN03dKikBE2qOkNfTQce2N0eNh1jUZhXwxTZHog=`

**Authorization header (F12)** — project canonical name sort order:
```
OAuth oauth_consumer_key="ck_test", oauth_nonce="abc123nonce", oauth_signature="PhzkFN03dKikBE2qOkNfTQce2N0eNh1jUZhXwxTZHog=", oauth_signature_method="HMAC-SHA256", oauth_timestamp="1700000000", oauth_token="at_test", oauth_version="1.0"
```

---

## 5. Recommendation

**reasoned recommendation:** Adopt the **isolated project-owned signer**
implementing `App\Support\Connectors\OAuth1\OAuth1RequestSigner` per §4. Do
**not** add a third-party OAuth library in Phase B.

### Why not a library?

1. **`api-clients/psr7-oauth1`** — the only appropriately-scoped signer-only
   package — cannot install without PSR-7 downgrade (§3.1).
2. **`horde/oauth`** — installs cleanly and exposes isolatable
   `SignedRequest`/`HmacSha256`, but is a full OAuth 2/OIDC client library
   adding `horde/jwt` and PSR-15 server middleware packages the connector
   runtime does not need; `AuthenticatedHttpClient` is unusable for Adobe query
   signing without an equivalent custom wrapper; `ext-openssl` is required for
   unused RSA paths. The wrapper code size ≈ building the isolated signer.
3. **All other candidates** — fail on SHA256, solver compatibility, abandoned
   status, security advisories, or Guzzle-stack coupling (§3.8).

### Why isolated signer?

1. Matches the fallback path already documented in `07-TECH_STACK.md` (Task
   4B-2-0 Stop-and-Amend gate this task completes).
2. Zero dependency-graph churn; no new security/advisory surface.
3. Full control over Adobe header parameter set, `oauth_version` policy, and
   signer/transport separation for B13 `CURLOPT_RESOLVE`.
4. Deterministic fixture tests (§4.7) are mandatory either way — the isolated
   path makes them first-class without mocking vendor classes.
5. ~195 LOC production code is proportionate to a single integration profile's
   signing needs; connector independence (Architecture principle §7) favors
   keeping vendor-specific OAuth mechanics behind a narrow internal port.

### Phase B gate

**open option requiring approval:** Proceed to Phase B only after explicit human
approval of this recommendation. Phase B deliverables (blocked until then):

- `OAuth1RequestSigner` port + isolated implementation
- Deterministic fixture test suite (§4.7)
- Adapter/registry/credentials service shell per Task 4B-2a scope

---

## Appendix A — Mandatory reading confirmation

| File | Read in Phase A session |
|---|---|
| `docs/03-DOMAIN_MODEL.md` (ConnectorAccount, capabilities, credentials, authorization) | Yes — consistency check in §Documentation consistency check |
| `docs/04-ARCHITECTURE_PRINCIPLES.md` (22-item checklist) | Yes — see Appendix B |
| `docs/07-TECH_STACK.md` (Connector runtime, Adobe OAuth signing, SSRF) | Yes |
| `docs/IMPLEMENTATION_GAPS.md` (GAP-006, GAP-024) | Yes |
| `docs/05-AI_WORKING_AGREEMENT.md` (Stop-and-Amend, Strict Alignment) | Yes |
| `composer.json`, `composer.lock` | Yes — §1 |
| `app/Models/ConnectorAccount.php` | Yes |
| `app/Models/ConnectorDefinition.php` | Yes |
| `app/Enums/ConnectorErrorCause.php` | Yes |
| `app/Enums/ConnectorErrorActionability.php` | Yes |
| `docs/proposals/task-4b2-0-runtime-decisions.md` (format template) | Yes |

---

## Appendix B — Architecture Review Checklist (research-only applicability)

All 22 items from `docs/04-ARCHITECTURE_PRINCIPLES.md` were read. For this
**docs-only Phase A PR**, no application code, migrations, or dependencies
change — checklist items are **not yet applicable at code-compliance level**.
They become binding in Phase B:

| # | Item | Phase A applicability |
|---|---|---|
| 1 | Tenant Isolation | N/A — no schema/code |
| 2 | Automated Scoping | N/A |
| 3 | Authorization and RBAC | Informational — signer sketch respects existing `ConnectorAccountPolicy` boundary |
| 4 | Attribute Dictionary Integrity | N/A |
| 5 | Attribute Storage Split | N/A |
| 6 | JSONB Localization | N/A |
| 7 | Field Duplication / aliases | N/A |
| 8 | Clean Domain Separation | Informational — signer stays in `App\Support\Connectors\OAuth1\` |
| 9 | Variant Cardinality | N/A |
| 10 | B2B Channel Projection | N/A |
| 11 | Order and Payment Autonomy | N/A |
| 12 | WorkspaceOrderStatusMatrix | N/A |
| 13 | Payment Webhook Routing | N/A |
| 14 | InventoryReservation | N/A |
| 15 | Net Stock / AvailabilityResolver | N/A |
| 16 | Historical Order Immutability | N/A |
| 17 | Connector Encapsulation | **Informs recommendation** — signer behind narrow port |
| 18 | No Hardcoded Clients | N/A |
| 19 | Payment Data Safety | N/A |
| 20 | Hidden Technical Complexity | N/A |
| 21 | External URL and SSRF Safety | **Informs sketch** — §4.4 signer/transport separation |
| 22 | Connector Secret Handling | **Informs sketch** — credentials passed in, never logged |

---

## Appendix C — PRE-CODE ARCHITECTURAL ALIGNMENT (for PR description)

```md
## PRE-CODE ARCHITECTURAL ALIGNMENT
- **Task Type:** Strict Alignment / Stop-and-Amend (Phase A research only).
- **Docs Checked:** `03-DOMAIN_MODEL.md`, `04-ARCHITECTURE_PRINCIPLES.md`,
  `05-AI_WORKING_AGREEMENT.md`, `07-TECH_STACK.md`, `IMPLEMENTATION_GAPS.md`,
  `composer.json`, `composer.lock`, `ConnectorAccount.php`,
  `ConnectorDefinition.php`, `ConnectorErrorCause.php`,
  `ConnectorErrorActionability.php`, `task-4b2-0-runtime-decisions.md` (format).
- **Affected Domain Contexts:** Connectors and Mappings.
- **Primary Sources & Standards:** RFC 5849, Adobe Commerce OAuth authentication
  docs, Packagist/GitHub for candidate libraries, libcurl CURLOPT_RESOLVE docs,
  PHP hash_hmac/random_bytes docs.
- **Architecture Checklist Result:** All 22 items read; none require code-level
  compliance in this docs-only PR. Items 17, 21, 22 inform the recommendation.
- **Chosen Direction:** Phase A research + recommendation only; Phase B blocked
  pending approval. Recommend isolated `OAuth1RequestSigner` (no new dependency).
- **Stop & Amend Required:** Yes — this task completes the Stop-and-Amend gate
  defined in `07-TECH_STACK.md` §Adobe PaaS OAuth 1.0a signing.
- **Documentation inconsistency noted:** `03-DOMAIN_MODEL.md` sections
  "Connector adapter capabilities" and "Credential and settings classification"
  remain `(proposed)` despite task brief expecting `(Resolved)` — not patched
  in this research-only task.
```
