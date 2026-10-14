#!/usr/bin/env bash
# Local mirror of the CI `security-audit` job (.github/workflows/tests.yml).
# Run it BEFORE every push: new advisories get published upstream all the time
# and CI fails the day one lands, even if this repo did not change.
#   scripts/audit-gate.sh
# Exit code 1 = a new/unaccepted advisory exists -> fix it (composer/npm update)
# or, only if it is genuinely accepted risk, document it in
# docs/security-advisories.md and docs/accepted-security-advisories.json.
set -u
cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
status=0

echo "== composer audit gate =="
COMPOSER_JSON="$($COMPOSER_BIN audit --format=json --no-interaction 2>/dev/null)"
COMPOSER_JSON="$COMPOSER_JSON" $PHP_BIN -r '
  $accepted = json_decode(file_get_contents("docs/accepted-security-advisories.json"), true)["composer"];
  $result = json_decode(getenv("COMPOSER_JSON"), true);
  $new = [];
  foreach ($result["advisories"] ?? [] as $package => $advisories) {
    foreach ($advisories as $a) {
      $id = $a["advisoryId"] ?? $a["cve"] ?? $a["title"];
      if (!in_array($id, $accepted, true)) { $new[] = "$package: $id ({$a["title"]})"; }
    }
  }
  if ($new) { echo "NEW composer advisories:\n" . implode("\n", $new) . "\n"; exit(1); }
  echo "ok - no new composer advisories\n";
' || status=1

echo "== npm audit gate =="
node -e '
  const fs = require("fs");
  const { execSync } = require("child_process");
  const accepted = new Set(JSON.parse(fs.readFileSync("docs/accepted-security-advisories.json")).npm);
  let data;
  try { data = JSON.parse(execSync("npm audit --json", { maxBuffer: 1024 * 1024 * 20 })); }
  catch (e) { data = JSON.parse(e.stdout); }
  const bad = [];
  for (const [name, vuln] of Object.entries(data.vulnerabilities || {})) {
    for (const via of vuln.via) {
      if (typeof via !== "object" || !via.url) continue;
      const m = via.url.match(/advisories\/(GHSA-[a-z0-9-]+)/i);
      const id = m ? m[1] : via.url;
      if (!accepted.has(id)) bad.push(`${name}: ${id} - ${via.title}`);
    }
  }
  if (bad.length) { console.log("NEW npm advisories:\n" + bad.join("\n")); process.exit(1); }
  console.log("ok - no new npm advisories");
' || status=1

exit $status
