<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the properties of the deploy workflows that only show up when they go
 * wrong: that a red (or never-run) test suite cannot reach production, and that
 * a release failing half-way does not abandon the site in maintenance mode.
 *
 * Like DockerImageHardeningTest these assertions only read files off disk, so
 * they extend PHPUnit's own TestCase instead of booting the application.
 */
class DeployWorkflowTest extends TestCase
{
    /**
     * Every workflow that puts the site into maintenance mode, and the job that
     * does it. Each one has to be able to get back out again.
     */
    public static function remoteScriptProvider(): array
    {
        return [
            'production deploy' => ['deploy-production.yml', 'deploy'],
            'staging deploy' => ['deploy-staging.yml', 'deploy'],
            'rollback' => ['rollback.yml', 'rollback'],
        ];
    }

    /**
     * Workflows that release a new commit, and so have a previous one to fall
     * back to. The rollback workflow is itself the fallback, so it is excluded.
     */
    public static function releaseWorkflowProvider(): array
    {
        return [
            'production deploy' => ['deploy-production.yml'],
            'staging deploy' => ['deploy-staging.yml'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(string $name = 'deploy-production.yml'): array
    {
        $path = dirname(__DIR__, 2).'/.github/workflows/'.$name;

        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    /**
     * The remote script a workflow feeds to appleboy/ssh-action.
     */
    private function remoteScript(string $name = 'deploy-production.yml', string $job = 'deploy'): string
    {
        foreach ($this->workflow($name)['jobs'][$job]['steps'] as $step) {
            if (isset($step['with']['script'])) {
                return $step['with']['script'];
            }
        }

        $this->fail("{$name} runs no remote script at all.");
    }

    private function ciGateStep(): array
    {
        foreach ($this->workflow()['jobs']['ci-gate']['steps'] as $step) {
            if (isset($step['run']) && str_contains($step['run'], 'workflows/tests.yml')) {
                return $step;
            }
        }

        $this->fail('The ci-gate job never inspects the CI workflow runs.');
    }

    public function test_the_deploy_job_waits_for_the_ci_gate(): void
    {
        // A v* tag fires this workflow directly and tests.yml only runs on
        // branch pushes, so nothing else stops a tag on a red commit.
        $this->assertSame(
            'ci-gate',
            $this->workflow()['jobs']['deploy']['needs'] ?? null,
            'The production deploy must not start before CI has been verified for the commit.'
        );
    }

    public function test_the_ci_gate_only_accepts_a_successful_run_for_the_same_commit(): void
    {
        $run = $this->ciGateStep()['run'];

        $this->assertStringContainsString('head_sha=$SHA', $run, 'The gate must check the commit being deployed, not the newest CI run.');
        $this->assertStringContainsString('!= "success"', $run);
        $this->assertStringContainsString('exit 1', $run);
    }

    public function test_the_ci_gate_can_only_be_skipped_by_a_manual_run(): void
    {
        // The escape hatch is an emergency lever, so it has to be an input a
        // human ticks — never something a pushed tag can set.
        $this->assertArrayHasKey(
            'skip_ci_check',
            $this->workflow()['on']['workflow_dispatch']['inputs'],
            'Without a documented escape hatch the gate blocks emergency hotfixes.'
        );

        $this->assertStringContainsString(
            'inputs.skip_ci_check',
            $this->ciGateStep()['if'] ?? '',
            'The gate must be conditional on the manual input only.'
        );
    }

    /**
     * @dataProvider remoteScriptProvider
     */
    public function test_the_remote_script_always_lifts_maintenance_mode(string $name, string $job): void
    {
        // Before the trap, a failing composer/npm/migrate step aborted the
        // script under `set -e` and `artisan up` never ran, leaving the site
        // showing the maintenance page until someone SSHed in.
        $this->assertMatchesRegularExpression(
            '/trap\s+\'php artisan up.*\'\s+EXIT/',
            $this->remoteScript($name, $job),
            "{$name} must lift maintenance mode on every exit path."
        );
    }

    /**
     * @dataProvider releaseWorkflowProvider
     */
    public function test_a_failed_release_is_rolled_back_and_reported_red(string $name): void
    {
        $script = $this->remoteScript($name);

        $this->assertMatchesRegularExpression(
            '/git (checkout --force|reset --hard) "\$PREVIOUS_RELEASE"/',
            $script,
            "{$name} must put the previously deployed code back when a release fails."
        );

        // The recovery must not swallow the failure: the run stays red so a
        // failed deploy is not mistaken for a success.
        $this->assertStringContainsString('exit 1', $script);
    }

    public function test_the_rollback_resolves_its_ref_before_entering_maintenance_mode(): void
    {
        $script = $this->remoteScript('rollback.yml', 'rollback');

        $refPosition = strpos($script, 'PREVIOUS_RELEASE');
        $downPosition = strpos($script, 'artisan down');

        $this->assertIsInt($refPosition);
        $this->assertIsInt($downPosition);
        $this->assertLessThan(
            $downPosition,
            $refPosition,
            'A missing PREVIOUS_RELEASE must fail before the site is taken down, not after.'
        );
    }

    /**
     * @dataProvider remoteScriptProvider
     */
    public function test_the_remote_script_is_valid_posix_shell(string $name, string $job): void
    {
        // appleboy/ssh-action runs the script under the deploy user's login
        // shell, which may well be dash — so bashisms (`set -o pipefail`) would
        // abort on the very first line.
        $shell = null;
        foreach (['/bin/dash', '/bin/sh', '/bin/bash'] as $candidate) {
            if (is_executable($candidate)) {
                $shell = $candidate;
                break;
            }
        }

        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $script = $this->remoteScript($name, $job);

        $this->assertStringNotContainsString('pipefail', $script, "{$name} uses a bashism the remote shell may not have.");

        // Expressions are interpolated by Actions before the script is sent.
        $process = new Process([$shell, '-n'], null, null, preg_replace('/\$\{\{[^}]*\}\}/', 'placeholder', $script));
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * @dataProvider remoteScriptProvider
     */
    public function test_the_remote_script_quotes_the_deploy_path(string $name, string $job): void
    {
        $this->assertStringContainsString(
            'cd "${{ secrets.DEPLOY_PATH }}"',
            $this->remoteScript($name, $job),
            "{$name} would cd somewhere else entirely if DEPLOY_PATH contained a space."
        );
    }
}
