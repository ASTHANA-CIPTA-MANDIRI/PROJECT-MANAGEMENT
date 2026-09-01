<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the properties of the container image that cannot be asserted from
 * inside the application: what ends up baked into a published artifact, and
 * what the entrypoint does to the database on every start.
 *
 * These assertions only read files off disk, so this extends PHPUnit's own
 * TestCase — booting the Laravel application (and with it base_path()) would
 * buy nothing.
 */
class DockerImageHardeningTest extends TestCase
{
    private function file(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).'/'.$relativePath;

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
        $this->assertStringNotContainsString(
            'artisan serve',
            $this->instructions('run.sh'),
            'artisan serve is PHP\'s single-threaded development server.'
        );
        $this->assertStringContainsString('supervisord', $this->file('run.sh'));
    }

    public function test_the_image_builds_the_front_end_assets(): void
    {
        // Building at run time meant the published image shipped without any
        // compiled assets at all.
        $this->assertStringContainsString('npm run build', $this->instructions('Dockerfile'));
        $this->assertStringNotContainsString('npm run build', $this->instructions('run.sh'));
    }

    public function test_the_container_does_not_run_as_root(): void
    {
        $this->assertStringContainsString('USER www-data', $this->file('Dockerfile'));
    }

    public function test_the_entrypoint_does_not_flush_the_shared_cache_store(): void
    {
        // optimize:clear includes cache:clear. With CACHE_DRIVER=redis or
        // database that store is shared, so restarting one container would
        // empty the application cache for every other one.
        $runScript = $this->instructions('run.sh');

        $this->assertStringNotContainsString('optimize:clear', $runScript);
        $this->assertStringNotContainsString('cache:clear', $runScript);
    }

    public function test_the_supervisor_config_runs_the_task_scheduler(): void
    {
        // app/Console/Kernel.php schedules reports:daily, tickets:due-date-reminders
        // and cleanup:old-activities. Without schedule:work running as its own
        // supervised process, those commands are defined but never invoked —
        // there is no cron inside the container to fire schedule:run instead.
        $this->assertStringContainsString(
            'artisan schedule:work',
            $this->instructions('docker/supervisord.conf'),
            'Nothing runs the scheduled commands from app/Console/Kernel.php inside the container.'
        );
    }

    public function test_no_workflow_uses_an_unpinned_third_party_action(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') as $workflow) {
            $this->assertStringNotContainsString(
                '@master',
                file_get_contents($workflow),
                basename($workflow).' pins an action to a moving branch, so its code can change under us.'
            );
        }
    }

    public function test_the_image_build_workflow_pins_every_action_to_a_commit(): void
    {
        $workflow = $this->file('.github/workflows/docker-build.yml');

        preg_match_all('/uses:\s*(\S+)/', $workflow, $matches);

        $this->assertNotEmpty($matches[1], 'The image build workflow uses no actions at all.');

        foreach ($matches[1] as $use) {
            [, $ref] = explode('@', $use, 2) + [1 => ''];

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $ref,
                "{$use} is pinned to a tag, and a tag can be moved to point at different code after review."
            );
        }
    }
}
