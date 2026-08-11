<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the properties of the container image that cannot be asserted from
 * inside the application: what ends up baked into a published artifact, and
 * what the entrypoint does to the database on every start.
 */
class DockerImageHardeningTest extends TestCase
{
    private function file(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * Same, minus `#` comment lines — so a comment explaining why something is
     * absent does not read as the thing being present.
     */
    private function instructions(string $relativePath): string
    {
        return implode("\n", array_filter(
            preg_split('/\R/', $this->file($relativePath)),
            fn (string $line) => ! str_starts_with(ltrim($line), '#')
        ));
    }

    public function test_the_image_does_not_bake_in_an_application_key(): void
    {
        $this->assertStringNotContainsString(
            'key:generate',
            $this->instructions('Dockerfile'),
            'A key generated at build time is shared by everyone who pulls the image, '.
            'letting them forge session cookies and signed URLs. Inject APP_KEY at runtime.'
        );
    }

    public function test_the_entrypoint_requires_an_application_key(): void
    {
        $this->assertStringContainsString('APP_KEY', $this->file('run.sh'));
    }

    public function test_the_build_context_excludes_history_and_secrets(): void
    {
        $ignored = $this->file('.dockerignore');

        // `.git` would publish every past commit; `.env` would be copied over
        // the image's own .env by `COPY . .` and ship real credentials.
        foreach (['.git', '.env', '.env.*', 'node_modules', 'vendor'] as $pattern) {
            $this->assertContains(
                $pattern,
                preg_split('/\R/', $ignored),
                "{$pattern} must be excluded from the Docker build context."
            );
        }
    }

    public function test_the_entrypoint_does_not_seed_on_every_start(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(php\s+)?artisan\s+db:seed/m',
            $this->file('run.sh'),
            'Seeding on start re-inserts the default admin and lookup rows on an existing database.'
        );
    }

    public function test_the_entrypoint_does_not_serve_with_the_php_dev_server(): void
    {
        $runScript = $this->file('run.sh');

        $this->assertStringNotContainsString(
            'artisan serve',
            $runScript,
            'artisan serve is PHP\'s single-threaded development server.'
        );
        $this->assertStringContainsString('supervisord', $runScript);
    }

    public function test_the_image_builds_the_front_end_assets(): void
    {
        // Building at run time meant the published image shipped without any
        // compiled assets at all.
        $this->assertStringContainsString('npm run build', $this->file('Dockerfile'));
        $this->assertStringNotContainsString('npm run build', $this->file('run.sh'));
    }

    public function test_the_container_does_not_run_as_root(): void
    {
        $this->assertStringContainsString('USER www-data', $this->file('Dockerfile'));
    }

    public function test_no_workflow_uses_an_unpinned_third_party_action(): void
    {
        foreach (glob(base_path('.github/workflows/*.yml')) as $workflow) {
            $this->assertStringNotContainsString(
                '@master',
                file_get_contents($workflow),
                basename($workflow).' pins an action to a moving branch, so its code can change under us.'
            );
        }
    }
}
