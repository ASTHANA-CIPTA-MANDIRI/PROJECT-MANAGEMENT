<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The documentation site (docs/index.html, published to GitHub Pages) has no
 * build step: it pulls docsify, mermaid and its plugins straight from a CDN.
 * There is nothing to bundle them into, so the mitigation is the other one —
 * pin every asset to an exact version and verify it with Subresource
 * Integrity, otherwise a compromised CDN or a hijacked npm release executes
 * arbitrary JavaScript for every reader of the docs.
 *
 * These assertions are offline on purpose: they check how the tags are
 * written, not what the CDN currently serves, so they never make CI flaky.
 * Hashes are checked against the real files when a version is bumped:
 *
 *     curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
 */
class DocumentationAssetIntegrityTest extends TestCase
{
    /**
     * @return array<int, array{tag: string, url: string}>
     */
    private function externalAssetTags(): array
    {
        $html = file_get_contents(base_path('docs/index.html'));

        preg_match_all('#<(?:script|link)\b[^>]*>#i', $html, $tags);

        $external = [];

        foreach ($tags[0] as $tag) {
            if (! preg_match('#\b(?:src|href)="([^"]+)"#i', $tag, $attribute)) {
                continue;
            }

            $url = $attribute[1];

            // Protocol-relative and absolute URLs both leave this origin;
            // anything else is a relative path served by the docs site itself.
            if (str_starts_with($url, '//') || preg_match('#^https?://#i', $url)) {
                $external[] = ['tag' => $tag, 'url' => $url];
            }
        }

        return $external;
    }

    public function test_documentation_loads_external_assets_over_https_only(): void
    {
        $tags = $this->externalAssetTags();

        $this->assertNotEmpty($tags, 'Expected docs/index.html to reference external assets.');

        foreach ($tags as ['url' => $url]) {
            $this->assertStringStartsWith(
                'https://',
                $url,
                "Documentation asset {$url} must be requested over https:// (no protocol-relative or plain http URLs)."
            );
        }
    }

    public function test_every_external_documentation_asset_is_pinned_and_integrity_checked(): void
    {
        foreach ($this->externalAssetTags() as ['tag' => $tag, 'url' => $url]) {
            $this->assertMatchesRegularExpression(
                '#@\d+\.\d+\.\d+(?:[-+][\w.]+)?/#',
                $url,
                "Documentation asset {$url} must be pinned to an exact version; a floating range lets the CDN change the file under us."
            );

            $this->assertMatchesRegularExpression(
                '#\bintegrity="sha(?:256|384|512)-[A-Za-z0-9+/=]+"#',
                $tag,
                "Documentation asset {$url} is missing a Subresource Integrity hash."
            );

            $this->assertMatchesRegularExpression(
                '#\bcrossorigin="anonymous"#',
                $tag,
                "Documentation asset {$url} needs crossorigin=\"anonymous\"; without it the browser cannot verify its integrity hash."
            );
        }
    }
}
