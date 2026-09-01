# Known & Accepted Security Advisories

This project runs on Laravel 9 + Filament 2. A small set of advisories cannot
be resolved on that stack without breaking the build, so they are **formally
accepted and tracked here** with their risk assessment and the release that
clears them. The CI `security-audit` job enforces this list on every run: an
advisory ID here does not block CI, anything not here does — see
[CI handling](#ci-handling) below.

Reviewed: 2026-08-28.

## 0. Framework EOL status

| | Status |
|---|---|
| Laravel 9.52 | **EOL since 2024-02-08.** No more upstream security patches. |
| Filament 2.17 | **Past its security-support window since ~2025-03.** No more upstream security patches. |

**Not upgraded in this pass, deliberately.** Upgrading to Laravel 11+ /
Filament 3 is a separate, tracked piece of work (it changes the Vite manifest
path, among other breaking changes — see Advisory 1) and is out of scope for
a security-hardening pass that must stay behavior-preserving.

**Consequence of staying on an EOL stack:** if a new vulnerability is
disclosed in Laravel 9 or Filament 2 core itself (not in a dependency we
control), there will be no official patch — only the framework upgrade fixes
it. `composer audit` currently reports **0 advisories**, including 0 against
`laravel/framework` and `filament/filament` themselves, so this is a
forward-looking risk, not a currently-exploitable one. It is re-checked by
the CI gate on every push, so a new framework-level advisory shows up as a
CI failure the day it is published, not silently.

**This does not excuse application-level vulnerabilities.** EOL status is a
reason a *framework* bug can't be patched upstream; it is not a reason to
leave an *application-code* bug (authorization, injection, IDOR, etc.)
unfixed — those remain entirely within this repo's control regardless of
framework version, and are audited and fixed independently (see the ongoing
audit rounds referenced in this repo's commit history on branch `jarne`).

## 1. npm — esbuild / Vite / laravel-vite-plugin (dev-only)

| | |
|---|---|
| Packages | `esbuild` (≤0.24.2), `vite` (3.2.11, transitive of `laravel-vite-plugin`) |
| `npm audit` severity | 2 moderate, 1 high (one severity per affected package) |
| Advisory IDs | 14 GHSA records against the `vite@3.2.11` / `esbuild` range — full list in `docs/accepted-security-advisories.json` (`npm` key). Historical CVEs keep getting filed against old, already-released version ranges, so this list grows over time even though the installed version does not change; each new one is checked against the same risk assessment below before being added. |
| Example | GHSA-67mh-4wv8-2f99 — esbuild dev server can be reached by any origin |

**Risk: effectively none in production.**

- `vite` / `esbuild` are **`devDependencies`** — build tooling, not runtime. The
  only runtime npm dependency is `flowbite`.
- The compiled output in `public/build/*` (what production serves) contains **no
  esbuild/vite code**. Production runs `npm run build`, never `npm run dev`.
- The esbuild advisory only affects the **local dev server** (`npm run dev`)
  when it is exposed to a malicious website — a local-developer scenario.

**Why not fixed now:** the fix needs esbuild >0.24.2, i.e. Vite 6+, which needs
`laravel-vite-plugin` 1.x. That plugin moves the manifest to
`public/build/.vite/manifest.json`, which Laravel 9's Vite integration does not
read — it would break asset loading. So it is coupled to the framework upgrade.

**Resolved by:** upgrading to Laravel 11 + `laravel-vite-plugin` 1.x + Vite 6+
(then `npm audit` is clean).

**Interim mitigation:** never expose the Vite dev server publicly (bind to
localhost, as it is by default).

**Dev server config audit (2026-08-28):** `vite.config.js` sets no `server`
block at all — no `host`, `port`, `cors`, or `hmr` override. Vite 3's default
for `server.host` is `false`, i.e. the dev server only listens on
`localhost`/`127.0.0.1`, not `0.0.0.0` or the LAN. So `npm run dev` is not
reachable from another machine on the network out of the box, which is the
condition the esbuild/Vite advisories above actually require to be
exploitable. No config change was made — the file already reads correctly as
"use Vite's safest default," and adding an explicit `host: 'localhost'` would
only restate the existing default, not change behavior. If a future change
ever adds a `server.host` override (e.g. to test from a phone on the same
Wi-Fi), scope it to a local-only override (`.env`-driven or a personal,
gitignored `vite.config.local.js`), never commit a `0.0.0.0`/LAN-facing
default.

## 2. composer — abandoned transitive packages (not vulnerable)

| Package | Comes from | Replacement (later) |
|---|---|---|
| `tgalopin/html-sanitizer` | `filament/support` (Filament 2) | `symfony/html-sanitizer` |
| `league/uri-parser` | `tgalopin/html-sanitizer` | `league/uri-interfaces` |

**Risk: none.** "Abandoned" means unmaintained, **not** vulnerable —
`composer audit` reports **0 security advisories**. These are pulled in by
Filament 2 itself and cannot be removed while on Filament 2.

**Resolved by:** upgrading to Filament 3 (which uses `symfony/html-sanitizer`).

## 3. public/js — vendored third-party libraries (no npm/Vite entry)

| File | Library | Version | Upstream | License |
|---|---|---|---|---|
| `public/js/Sortable.js` | SortableJS | 1.15.0 (banner comment in the file) | github.com/SortableJS/Sortable | MIT |
| `public/js/jsgantt.js`, `public/css/jsgantt.css` | jsgantt-improved | 1.8.0 (`/* Sample CSS for jsGanttImproved v1.8.0 */` in the CSS) | github.com/jsGanttImproved/jsgantt-improved | ISC |

**Where used:** `Sortable.js` is loaded by `<script>` tag in
`resources/views/filament/pages/kanban.blade.php` and `scrum.blade.php` for
drag-and-drop card reordering; `jsgantt.js`/`jsgantt.css` are loaded the same
way in `road-map.blade.php` for the Gantt chart. All three call the vendored
files' global objects (`window.Sortable`, `window.JSGantt`) directly from
inline `<script>` blocks in those views — they are not imported anywhere, so
npm/`npm audit`/Vite has no visibility into them and cannot track advisories
for them automatically.

**Local modification check (2026-08-25):** neither file carries any
project-specific edit markers. `Sortable.js` still has its original upstream
minified-build banner (`/*! Sortable 1.15.0 - MIT | git://github.com/SortableJS/Sortable.git */`)
and reads as a stock UMD build. `jsgantt.css` still has jsgantt-improved's own
stock top-of-file comment naming its exact version, and `jsgantt.js` is a
browserify bundle consistent with that package's normal `dist/` build output.
No byte-for-byte diff against the published npm tarballs was performed (no
network package install in this pass), so this is a structural check, not a
cryptographic one — worth a follow-up if either file is ever touched again.

**Known advisories:** none found for either package or version as of this
review (checked npm registry + Snyk's advisory DB). Re-check the same way
before relying on this again, since new advisories can land at any time:
`https://security.snyk.io/package/npm/sortablejs` and
`https://security.snyk.io/package/npm/jsgantt-improved`.

**Why not migrated to npm/Vite now:**

- SortableJS is a low-risk, near-current drop-in (upstream is at 1.15.7 — the
  same 1.15 line, patch releases only), but both Kanban pages call it as a
  bare global from inline script blocks; wiring that through a Vite entry
  means reworking those blocks to import it as a module, which is more than a
  version bump and needs a real browser check of drag-and-drop on both boards.
- jsgantt-improved has moved two major versions since 1.8.0 (current upstream
  is 3.0.0). A major-version jump this size is exactly the kind of change
  this pass should not force through without the ability to visually verify
  the Road Map page in a browser and diff its rendered output — the finding
  this advisory responds to explicitly calls for not forcing a library swap
  without testing compatibility first.

**Interim mitigation:** none required — both are client-side UI libraries
that do not parse or execute untrusted server data, so there is no known
attack surface being left open by staying vendored. Revisit alongside the
Laravel 11 / Vite 6 upgrade already tracked in Advisory 1, since that pass
already reworks the frontend build tooling.

## 4. Accepted design: Sanctum token lifetime, no refresh-token

**Status: reviewed 2026-08-28, no bug found — current design accepted.**

| | |
|---|---|
| Expiration | `SANCTUM_TOKEN_EXPIRATION`, default 20160 minutes (14 days) — `config/sanctum.php` |
| Stamping | Every token gets an `expires_at` clamped to that window on issue, even if the caller asks for longer — `app/Support/ApiTokenIssuer.php` |
| Enforcement | Confirmed against `vendor/laravel/sanctum/src/Guard.php`: a request is only authenticated if **both** the global window (`created_at` vs. now) **and** the stamped `expires_at` are unexpired. Regression test: `test_an_expired_token_cannot_be_used` in `tests/Feature/Api/ApiTokenTest.php` — creates a real token through the app's own issuance endpoint, travels past its expiry with `travelTo()`, and asserts the request is then rejected. |
| Revocation | `DELETE /api/v1/tokens/{id}` (self-service, panel + API) deletes the row outright — immediate, not a soft flag. A token may revoke itself (the panic button for a leaked credential) but may not mint a new one, so a leaked token cannot renew its own life. |
| Plaintext exposure | Audited: the plaintext secret is returned once in the issuance response and held in a single Livewire property for one render; nothing logs it, no request-body logging middleware exists in this app, and it is never written to a custom column (only the hash is persisted, per Sanctum's own `personal_access_tokens.token`). |
| Abilities | Every token gets `['*']`; authorization is enforced by the user's roles/policies, not token scope, so a narrower ability list would not add a real restriction — documented in `ApiTokenIssuer`'s class docblock. |
| Route protection | Every versioned API route (`routes/api.php`, `v1` group) sits behind `auth:sanctum`; rate limiting (`throttle:api`, 100 req/min) applies to the whole `api` middleware group. |

**Refresh-token: deliberately not built.** A rotation/refresh mechanism adds a
new endpoint, new storage, rotation logic, replay protection and its own test
surface — real scope for a project on a 14-day expiration with working manual
revocation. Refresh-token can be considered for long-lived client integrations
in a later pass; it is not a gap in the current design.

## 5. Accepted design: queue driver stays `database`

**Status: reviewed 2026-08-28, no bug found — current design accepted.**

`QUEUE_CONNECTION=database` (`config/queue.php`, `.env.example`) is a
deliberate choice so the app doesn't require a Redis service to run at all.
Verified as actually working, not just configured:

- `jobs` and `failed_jobs` tables exist (`database/migrations/2022_11_08_150309_create_jobs_table.php`, `2019_08_19_000000_create_failed_jobs_table.php`).
- The Docker image runs a supervised worker (`docker/supervisord.conf`,
  `[program:queue]`: `queue:work --tries=3 --sleep=3 --max-time=3600`) and a
  supervised scheduler (`[program:scheduler]`: `schedule:work`), both guarded
  by `tests/Unit/DockerImageHardeningTest.php` so a future edit can't silently
  drop either process.
- Bare-metal deployment documents the same worker under systemd/Supervisor
  (`docs/deployment.md`, "Queue worker").

Redis is not added to `docker-compose.yml` or made the default — see
[Future production scaling](deployment.md#4-future-production-scaling-not-enabled-by-default)
in `docs/deployment.md` for the documented (not enabled) upgrade path for
multi-instance or high-throughput deployments.

## 6. composer — `spatie/laravel-medialibrary` locked below its patched version

| | |
|---|---|
| Installed | 10.15.0 (the latest 10.x release — confirmed via Packagist, there is no 10.x backport) |
| Fixed in | 11.23.0 |
| Advisory IDs | `PKSA-mrgr-9pdf-y591` (CVE-2026-48557, **high** — file upload restriction bypass), `PKSA-88bj-c5ky-3pr6` (CVE-2026-48555, **medium** — SSRF) |

**Why not fixed now:** `filament/spatie-laravel-media-library-plugin` is
pinned to `^2.16` (the Filament 2 line, same reason as Advisory 0). Every
`2.x` release of that plugin requires `spatie/laravel-medialibrary: ^9.0|^10.0`
— confirmed by checking every published `2.x` tag's `composer.json`, none of
them ever raise that ceiling. The `^11.0` requirement only starts at the
plugin's `v5.x` line, which is the Filament 3 plugin. So this is coupled to
the same Filament 3 upgrade as Advisory 0/2, not something `composer update`
inside this stack can reach.

**Exposure check (2026-08-31):** `spatie/laravel-medialibrary` is used by two
models, `App\Models\Ticket` and `App\Models\Project` (`InteractsWithMedia`),
both through Filament's `SpatieMediaLibraryFileUpload` form component behind
`auth` — no public/anonymous upload path. Neither model overrides
`registerMediaCollections()` with an explicit MIME allow-list, so the file
upload restriction bypass advisory is not moot: an authenticated user could
still upload a file whose real content doesn't match its extension/MIME.
`addMediaFromUrl()` (the SSRF advisory's vector) is not called anywhere in
`app/` — grepped for it, no hits — so that vector needs a feature this app
doesn't use to be reachable.

**Interim mitigation:** uploads are only reachable by authenticated staff
users (Filament panel), not public visitors, which narrows the file-upload
advisory to a malicious-insider or compromised-account scenario rather than
an unauthenticated one. No code change made to add an explicit MIME
allow-list in this pass — that would be a real behavior change (rejecting
uploads that work today) outside the scope of a dependency-audit pass, and is
tracked as a follow-up independent of the Filament 3 upgrade.

**Resolved by:** upgrading to Filament 3 + `filament/spatie-laravel-media-library-plugin` v5.x, which pulls `spatie/laravel-medialibrary` ^11 (same tracked piece of work as Advisory 0).

## 7. composer — `laravel/framework` core advisories (no in-range fix, same as Advisory 0)

| | |
|---|---|
| Installed | v9.52.20 (the latest 9.x release — `composer show -a laravel/framework` confirms 9.52.22 is the newest tag on this line, and its changelog carries no security fix) |
| Advisory IDs | `PKSA-m5cs-t1y6-qpcs` (medium, Temporary Signed URL Path Confusion, fixed 12.61.1), `PKSA-3r5d-mb8f-1qw9` / `PKSA-mdq4-51ck-6kdq` (**high** — CRLF injection in the default `email` validation rule, CVE-2026-48019, fixed 12.60.0 — these two IDs are the same underlying GitHub advisory `GHSA-5vg9-5847-vvmq`, reported to Packagist twice), `PKSA-8qx3-n5y5-vvnd` (medium, File Validation Bypass, CVE-2025-27515, fixed 10.48.29/11.44.1/12.1.1) |

**Why not fixed now:** every one of these advisories' `affectedVersions` range
starts unbounded below its fixed version (e.g. `<12.61.1`, with no `>=9.x`
floor), meaning **no 9.x release ever fixed them** — same EOL situation as
Advisory 0. There is no patch to pull with a `composer update
laravel/framework`; clearing them requires the major-version upgrade to
Laravel 12+ that Advisory 0 already tracks as separate, out-of-scope work.

**Exposure check (2026-08-31):**

- **Signed URL path confusion** — `URL::temporarySignedRoute()` is used in
  `App\Notifications\CustomVerifyEmail` for the email-verification link. The
  advisory is about ambiguous path parsing letting a signed URL be replayed
  against a different route; this app's verification route takes no
  attacker-influenced path segments beyond the signed user/hash IDs Laravel
  itself puts there, which narrows (but does not eliminate) the practical
  attack surface.
- **CRLF injection in the `email` rule** — the built-in `'email'` validation
  rule is used in `App\Models\User` and `App\Filament\Resources\UserResource`.
  The bug lets a crafted string pass email-format validation while containing
  CRLF, which matters if that value is later dropped into a raw mail header.
  This app sends mail through Laravel's own `Mail`/`Notification` layer
  (never hand-building raw SMTP headers from user input), so the realistic
  path is narrow, but it isn't zero — worth re-checking if a new feature ever
  writes a validated email address into a header directly.
- **File Validation Bypass** — grepped `app/` for the `'file'`/`mimes:`/
  `mimetypes:` validation rules this advisory affects: no hits. File uploads
  in this app go through Filament's `FileUpload`/`SpatieMediaLibraryFileUpload`
  components (their own validation path, discussed in Advisory 6), not
  Laravel's `File` rule directly, so this specific advisory's bypass does not
  apply to any validation this codebase actually performs.

**Interim mitigation:** none beyond the narrow exposure above — no code
change made, since the actual gap is upstream in the framework, not in how
this app calls it.

**Resolved by:** the Laravel 12 upgrade already tracked in Advisory 0.

## CI handling

The `security-audit` job in `.github/workflows/tests.yml` is a **hard gate**,
not an advisory-only job — it has no job-level `continue-on-error`. It runs
`composer audit --format=json` and `npm audit --json`, and compares every
advisory ID found against `docs/accepted-security-advisories.json`:

- **Known/accepted** (listed in that file, with a risk assessment on this
  page) → logged as `ACCEPTED`, does **not** fail the job.
- **New/unaccepted** (anything not in that file — a fresh advisory in
  `laravel/framework`, `filament/filament`, a new npm package, or a new GHSA
  ID against an existing one) → fails the job.

Two purely informational steps (`composer audit --abandoned=report` for
human-readable abandoned-package reporting, and `npm audit` for a
human-readable log) carry a step-level `continue-on-error: true` so their own
exit code — which does not distinguish accepted from new — never masks the
gate step that runs right after them and makes the real pass/fail call.

**When `composer audit` or `npm audit` reports something new:** don't add it
to `docs/accepted-security-advisories.json` to make CI pass. Fix it if a
compatible patch/minor exists (see the PATCH NOW / SAFE FOLLOW-UP triage
elsewhere in this repo's audit history); only add it to the accepted list —
alongside a risk assessment on this page, same as Advisories 1–3 above — if a
maintainer has actually decided it's accepted risk.
