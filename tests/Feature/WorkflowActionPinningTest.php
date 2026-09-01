<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A GitHub Action referenced by tag (`@v4`) runs whatever commit that tag
 * happens to point at when the job starts, and tags are mutable: whoever can
 * move the tag can run their code inside a workflow holding our secrets.
 * Pinning to a commit SHA takes that away.
 *
 * Only the workflows that are already pinned are guarded here. The remaining
 * ones (deployment/SSH and the assorted third-party helpers) still use tags;
 * add them to $pinnedWorkflows as they are converted.
 */
class WorkflowActionPinningTest extends TestCase
{
    /** @var array<int, string> */
    private array $pinnedWorkflows = [
        'tests.yml',
        'docker-build.yml',
    ];

    /**
     * @return array<int, array{file: string, line: int, uses: string}>
     */
    private function actionReferences(string $workflow): array
    {
        $path = base_path(".github/workflows/{$workflow}");
        $this->assertFileExists($path);

        $references = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $index => $line) {
            if (preg_match('/^\s*-?\s*uses:\s*(\S+)/', $line, $matches)) {
                $references[] = [
                    'file' => $workflow,
                    'line' => $index + 1,
                    'uses' => $matches[1],
                ];
            }
        }

        return $references;
    }

    public function test_every_action_in_a_pinned_workflow_is_pinned_to_a_commit_sha(): void
    {
        $referenceCount = 0;

        foreach ($this->pinnedWorkflows as $workflow) {
            foreach ($this->actionReferences($workflow) as $reference) {
                $referenceCount++;

                // owner/repo@<40 hex chars>, optionally with a ./sub/path.
                $this->assertMatchesRegularExpression(
                    '/^[\w.-]+\/[\w.-]+(\/[\w.\/-]+)?@[0-9a-f]{40}$/',
                    $reference['uses'],
                    "{$reference['file']}:{$reference['line']} uses a mutable reference "
                        ."({$reference['uses']}); pin it to a commit SHA."
                );
            }
        }

        // Guard the guard: a renamed or emptied workflow must not turn this
        // into a test that asserts nothing.
        $this->assertGreaterThanOrEqual(10, $referenceCount);
    }

    public function test_each_pinned_action_records_the_version_it_stands_for(): void
    {
        foreach ($this->pinnedWorkflows as $workflow) {
            $lines = file(base_path(".github/workflows/{$workflow}"), FILE_IGNORE_NEW_LINES);

            foreach ($this->actionReferences($workflow) as $reference) {
                $this->assertStringContainsString(
                    '#',
                    $lines[$reference['line'] - 1],
                    "{$reference['file']}:{$reference['line']} pins a SHA without a trailing "
                        .'comment naming the version it corresponds to.'
                );
            }
        }
    }
}
