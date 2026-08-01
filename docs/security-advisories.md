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

## CI handling

- `composer audit --abandoned=report` — reports abandoned packages but only
  fails on real security advisories (currently none).
- `npm audit --audit-level=critical` — the dev-only moderate/high advisories
  above do not fail the build; a genuine critical would.
- The `security-audit` job is advisory (non-blocking) until the framework
  upgrade lands, after which it should be made a required check.
