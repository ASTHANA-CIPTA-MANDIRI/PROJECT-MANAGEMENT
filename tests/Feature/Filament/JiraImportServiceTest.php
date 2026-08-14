<?php

namespace Tests\Feature\Filament;

use App\Services\JiraImportService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Everything the Jira REST API returns is remote input: keys are pasted into a
 * JQL query, and `self` links are turned back into request paths. These tests
 * drive the service against a mocked transport — no Jira instance required —
 * to prove the queries are encoded, hostile keys are dropped, and unexpected
 * response shapes degrade instead of throwing.
 */
class JiraImportServiceTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /**
     * @param  array<int, Response>  $responses
     */
    private function clientReturning(array $responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client([
            'base_uri' => 'https://example.atlassian.net',
            'handler' => $stack,
        ]);
    }

    private function lastRequestUri(): string
    {
        return (string) $this->history[count($this->history) - 1]['request']->getUri();
    }

    private function lastQuery(string $key): ?string
    {
        parse_str(parse_url($this->lastRequestUri(), PHP_URL_QUERY) ?? '', $query);

        return $query[$key] ?? null;
    }

    public function test_the_project_key_is_quoted_and_encoded_in_the_jql_query(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['total' => 0, 'issues' => []])),
        ]);

        app(JiraImportService::class)->fetchTicketsByProject($client, ['ALP']);

        $this->assertSame('/rest/api/2/search', parse_url($this->lastRequestUri(), PHP_URL_PATH));
        $this->assertSame('project = "ALP"', $this->lastQuery('jql'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileProjectKeys(): array
    {
        return [
            'jql clause injection' => ['ALP" OR project = "SECRET'],
            'extra query parameter' => ['ALP&maxResults=1000'],
            'path traversal' => ['../../../admin'],
            'whitespace' => ['ALP OR 1=1'],
            'quote' => ['"'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider hostileProjectKeys
     */
    public function test_a_malformed_project_key_is_skipped_without_any_request(string $projectKey): void
    {
        $client = $this->clientReturning([]);

        $results = app(JiraImportService::class)->fetchTicketsByProject($client, [$projectKey]);

        $this->assertSame([], $results);
        $this->assertSame([], $this->history, 'no request should have been sent');
    }

    public function test_a_response_without_issues_does_not_throw(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['errorMessages' => ['Boom']])),
        ]);

        $results = app(JiraImportService::class)->fetchTicketsByProject($client, ['ALP']);

        $this->assertSame(['ALP' => ['total' => 0, 'issues' => []]], $results);
    }

    public function test_issues_missing_a_key_or_summary_are_tolerated(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['total' => 3, 'issues' => [
                ['key' => 'ALP-1', 'fields' => ['summary' => 'Real ticket']],
                ['key' => 'ALP-2'],
                ['fields' => ['summary' => 'No key at all']],
            ]])),
        ]);

        $results = app(JiraImportService::class)->fetchTicketsByProject($client, ['ALP']);

        $this->assertCount(2, $results['ALP']['issues']);
        $this->assertSame('Real ticket', $results['ALP']['issues'][0]['name']);
        $this->assertSame('ALP-2', $results['ALP']['issues'][1]['name']);
    }

    public function test_fetch_projects_drops_entries_the_wizard_cannot_render(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([
                ['key' => 'ALP', 'name' => 'Alpha'],
                ['key' => 'NONAME'],
                'not an object',
            ])),
        ]);

        $projects = app(JiraImportService::class)->fetchProjects($client);

        $this->assertCount(1, $projects);
        $this->assertSame('ALP', $projects[0]->key);
    }

    public function test_fetch_projects_returns_null_when_the_response_is_not_a_list(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['errorMessages' => ['Unauthorized']])),
        ]);

        $this->assertNull(app(JiraImportService::class)->fetchProjects($client));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function selfLinks(): array
    {
        return [
            'issue key' => ['https://example.atlassian.net/rest/api/2/issue/ALP-1', 'ALP-1'],
            'numeric id' => ['https://example.atlassian.net/rest/api/2/issue/10001', '10001'],
            'query is dropped' => ['https://example.atlassian.net/rest/api/2/issue/10001?expand=changelog', '10001'],
            'fragment is dropped' => ['https://example.atlassian.net/rest/api/2/issue/10001#x', '10001'],
            'spaces refused' => ['https://example.atlassian.net/rest/api/2/issue/ALP 1', null],
            'encoded slash refused' => ['https://example.atlassian.net/rest/api/2/issue/a%2F..%2Fadmin', null],
            'dot segment refused' => ['https://example.atlassian.net/rest/api/2/issue/..', null],
            'empty refused' => ['', null],
            'not a url refused' => ['@@@', null],
        ];
    }

    /**
     * @dataProvider selfLinks
     */
    public function test_only_the_last_path_segment_is_used_and_it_must_look_like_a_key(string $url, ?string $expected): void
    {
        $method = new ReflectionMethod(JiraImportService::class, 'issueKeyFromUrl');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $url));
    }

    public function test_a_self_link_that_is_not_an_issue_key_never_reaches_the_network(): void
    {
        // The host would be refused by the SSRF guard, so reaching connect()
        // at all would surface as an exception rather than a null return.
        $this->assertNull(
            app(JiraImportService::class)->fetchTicketDetails('http://127.0.0.1', 'user', 'token', 'http://127.0.0.1/rest/api/2/issue/not a key')
        );
    }

    public function test_a_valid_self_link_still_goes_through_the_host_guard(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(JiraImportService::class)->fetchTicketDetails('http://127.0.0.1', 'user', 'token', 'http://127.0.0.1/rest/api/2/issue/ALP-1');
    }
}
