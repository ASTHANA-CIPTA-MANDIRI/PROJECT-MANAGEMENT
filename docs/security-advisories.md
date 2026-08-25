# Known & Accepted Security Advisories

This project runs on Laravel 9 + Filament 2. A small set of advisories cannot
be resolved on that stack without breaking the build, so they are **formally
accepted and tracked here** with their risk assessment and the release that
clears them. The CI `security-audit` job surfaces them on every run.

Reviewed: 2026-07-28.

## 1. npm — esbuild / Vite / laravel-vite-plugin (3 advisories, dev-only)

| | |
|---|---|
| Packages | `esbuild` (≤0.24.2), `vite`, `laravel-vite-plugin` (transitive) |
| Severity | 2 moderate, 1 high |
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

## CI handling

- `composer audit --abandoned=report` — reports abandoned packages but only
  fails on real security advisories (currently none).
- `npm audit --audit-level=critical` — the dev-only moderate/high advisories
  above do not fail the build; a genuine critical would.
- The `security-audit` job is advisory (non-blocking) until the framework
  upgrade lands, after which it should be made a required check.
