<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the two properties of the production deploy that only show up when it
 * goes wrong: that a red (or never-run) test suite cannot reach the server, and
 * that a release failing half-way does not abandon the site in maintenance mode.
 *
 * Like DockerImageHardeningTest these assertions only read files off disk, so
 * they extend PHPUnit's own TestCase instead of booting the application.
 */
class DeployWorkflowTest extends TestCase
{
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
     * The remote script the deploy step feeds to appleboy/ssh-action.
     */
    private function releaseScript(): string
    {
        foreach ($this->workflow()['jobs']['deploy']['steps'] as $step) {
            if (isset($step['with']['script'])) {
                return $step['with']['script'];
            }
        }

        $this->fail('The production deploy job runs no remote script at all.');
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

    public function test_the_release_always_lifts_maintenance_mode(): void
    {
        // Before the trap, a failing composer/npm/migrate step aborted the
        // script under `set -e` and `artisan up` never ran, leaving production
        // showing the maintenance page until someone SSHed in.
        $this->assertMatchesRegularExpression(
            '/trap\s+\'php artisan up.*\'\s+EXIT/',
            $this->releaseScript(),
            'The remote script must lift maintenance mode on every exit path.'
        );
    }

    public function test_a_failed_release_is_rolled_back_and_reported_red(): void
    {
        $script = $this->releaseScript();

        $this->assertStringContainsString(
            'git checkout --force "$PREVIOUS_RELEASE"',
            $script,
            'A failed release must put the previously deployed code back.'
        );

        // The rollback must not swallow the failure: the run stays red so the
        // deploy is not mistaken for a success.
        $this->assertStringContainsString('exit 1', $script);
    }

    public function test_the_remote_script_is_valid_shell(): void
    {
        if (! is_executable('/bin/bash')) {
            $this->markTestSkipped('bash is not available.');
        }

        // Expressions are interpolated by Actions before the script is sent.
        $script = preg_replace('/\$\{\{[^}]*\}\}/', 'placeholder', $this->releaseScript());

        $process = new Process(['/bin/bash', '-n'], null, null, $script);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
