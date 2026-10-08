# Phase 3A: PWA private-page cache security correction

Date: 2026-10-08 (Asia/Manila)

## Scope and outcome

Sensitive worker-controlled requests now bypass CacheStorage and the browser HTTP cache. They never retrieve cached private content. Offline page requests receive only the public generic `/offline` document; notification polling continues to fail offline instead of receiving HTML in place of JSON.

Only these source/test/documentation files were changed in this phase:

1. `public/sw.js` — exclusion rules, response guards, cache version, and upgrade cleanup.
2. `tests/Frontend/pwa-private-pages.test.cjs` — ten security and public-cache regression tests.
3. `docs/phase-3a-pwa-security.md` — this report.

All pre-existing Phase 2 edits and the untracked `package-lock.json` were preserved. Hashes of 270 pre-existing tracked and Phase 2 source/test/configuration files, excluding the service worker, match the start-of-phase snapshot. No route, controller, middleware, model, reservation, Official Use, payment, notification, authentication, permission, schema, dependency, or asset-build implementation changed.

## Route review

The old rule omitted `/super-admin/analytics`, `/two-factor-challenge`, `/settings/security`, and the root redirect. These could enter the general navigation cache. Notification exclusions also lacked consistent descendant/trailing-slash handling.

Reviewed all GET and redirect declarations in `routes/web.php`, guest/security endpoints, authenticated/verified groups, and role-restricted controllers. The exclusions now cover:

| Area | Paths/namespaces |
| --- | --- |
| Authentication and challenge | `/`, `/login`, `/register`, `/forgot-password`, `/reset-password`, `/two-factor-challenge`, `/email`, `/logout` |
| Account security | `/settings/security`, `/profile` |
| Dashboards and analytics | `/dashboard`, `/calendar`, `/analytics`, `/super-admin` |
| Account oversight | `/admin`, `/admins` |
| Operations and reporting | `/reservations`, `/official-uses`, `/official-use-conflicts`, `/payments`, `/facilities` |
| Private notifications | `/notifications` |

Each namespace covers its descendants. The root exclusion is exact, so it does not exclude every public URL. Query strings do not affect classification. Percent-encoded characters/separators, backslashes, repeated slashes, and trailing slashes are normalized for classification; the actual network request is not rewritten. Malformed encoded paths bypass caches conservatively. Lookalike public prefixes such as `/analytics-guide` remain public.

POST, PATCH, PUT, and DELETE requests remain unintercepted. No application endpoint or authentication flow was changed.

## Cache correction and upgrade handling

- Bumped the cache from `ereserve-pwa-v14` to `ereserve-pwa-v15` so installations create a clean namespace.
- Sensitive GETs use `fetch(request, {cache: 'no-store'})`. This bypasses browser HTTP caching as well as the worker's explicit cache paths. See [MDN Request.cache](https://developer.mozilla.org/en-US/docs/Web/API/Request/cache).
- Every cache lookup names the current cache explicitly. It cannot fall through to a stale cache left or recreated by an earlier worker. See [MDN CacheStorage.match](https://developer.mozilla.org/en-US/docs/Web/API/CacheStorage/match).
- Activation deletes earlier `ereserve-pwa-*` caches, scrubs sensitive entries from the current cache, and then claims clients. Git history confirms older eReserve worker versions used this same prefix. Unrelated application caches are retained.
- Current-cache cleanup checks request URLs and cached response safety, including private responses stored under a public alias.
- Public-URL responses that resolve to a sensitive URL, carry `Cache-Control: private` or `no-store`, or are unsuccessful are not stored or replayed. Explicit checks are necessary because [CacheStorage does not enforce HTTP Cache-Control](https://developer.mozilla.org/en-US/docs/Web/API/Cache).
- Public cache writes use the fetch event's lifetime promise, so writes are tracked by the worker lifecycle.

The public static precache list remains identical. The explicitly public generic `/offline` entry is retained during activation even if Laravel supplies its default private HTTP header. Public navigation remains network-first with a safe cached-page/offline fallback; styles retain their network-first behavior; other public assets retain cache-first behavior. Public opaque assets remain supported.

Earlier cache versions, including their public navigation history, are discarded during the normal version upgrade. Public static precaching and future public navigation caching remain available.

## Regression coverage

The existing 26 JavaScript tests passed before changes. New regression tests were run against the old worker and reproduced six failures before the fix. Final coverage includes:

- Every current GET/redirect declaration, including dynamic IDs, online and offline.
- The two originally reported paths plus root/security redirects and other private namespaces.
- Queries, descendants, trailing/repeated slashes, encoded characters/separators, and malformed encodings.
- Non-navigation sensitive GETs and overriding a request's HTTP cache preference.
- Notification polling's existing offline failure behavior.
- Legacy-cache deletion and removal of contaminated current-cache entries before claiming clients.
- An old in-flight request recreating a legacy cache after activation: the new worker still cannot read it.
- Sensitive redirects and private/no-store responses stored under public aliases.
- Unchanged public precaching, generic offline fallback, public navigation, stylesheet/script caching, opaque assets, and public prefix lookalikes.
- Unchanged bypass of mutation requests.

No existing assertion or test was removed or weakened.

## Final verification

| Suite/check | Result |
| --- | --- |
| Complete Laravel feature and unit suite | 307 passed; 4,086 assertions; 0 failures/errors/skips; 54.723 seconds |
| Complete JavaScript `*.test.cjs` suite | 36 passed, including 10 new PWA security tests; 0 failures |
| Standalone private-page PWA checks | 32 checks passed |
| Standalone password-reset PWA checks | Passed all existing online/offline and POST assertions |
| All eleven existing Chrome browser suites | 335 checks passed; 0 failures |
| Service-worker and new test syntax | Passed |
| `git diff --check` | Passed |
| Protected source-file hashes | 270 unchanged |

Browser breakdown: UI consistency 210; facility cards 20; facility view/gallery 18; resident facility actions 12; time-layout 9; shared modals 16; payments 4; Official Use 4; reservation DataTable 28; analytics 4; dashboard 10.

Laravel and fixture runs used explicit test-only process settings: in-memory SQLite, empty database URL, array mail/cache/session, synchronous queue, and test-only configuration/route-cache paths. Existing test safeguards refuse a non-isolated database. No destructive command targeted application data. Chrome suites ran outside the execution sandbox because earlier phases established its GPU/access restrictions.

Logs, the pre-fix security reproduction, JUnit results, and source-file hashes are retained under `storage/app/phase3a-check`.

## Installation considerations

This change is local and has not been deployed. After deployment, existing clients receive the correction when the browser downloads and activates the changed `/sw.js`. Existing registration, `skipWaiting`, and client-claim behavior remain; claiming clients now waits for cleanup.

An installation that stays offline cannot download the correction or have its old caches remotely purged. Once the new worker activates, earlier eReserve caches are removed and cannot be used by its fetch handlers, even if an old in-flight request recreates a legacy cache.

Lifecycle/cache security is covered by deterministic service-worker simulations using browser-shaped requests/responses and native URL/Header parsing. The existing Chrome suites cover application interactions and rendering; deployment-specific worker-update timing has not been tested against a live production installation.
