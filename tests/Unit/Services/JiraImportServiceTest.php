<?php

namespace Tests\Unit\Services;

use App\Services\JiraImportService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class JiraImportServiceTest extends TestCase
{
    private JiraImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JiraImportService;
    }

    private function clientMocking(array $responses): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));

        return new Client(['handler' => $handler]);
    }

    public function test_connect_builds_a_client_with_the_given_host_and_basic_auth(): void
    {
        $client = $this->service->connect('https://example.atlassian.net', 'user@example.com', 'secret-token');

        $this->assertSame('https://example.atlassian.net', (string) $client->getConfig('base_uri'));
        $this->assertSame(
            'Basic '.base64_encode('user@example.com:secret-token'),
            $client->getConfig('headers')['Authorization']
        );
    }

    public function test_fetch_projects_returns_the_decoded_project_list(): void
    {
        $client = $this->clientMocking([
            new Response(200, [], json_encode([
                ['key' => 'ALP', 'name' => 'Alpha'],
                ['key' => 'BET', 'name' => 'Beta'],
            ])),
        ]);

        $projects = $this->service->fetchProjects($client);

        $this->assertCount(2, $projects);
        $this->assertSame('ALP', $projects[0]->key);
    }

    public function test_fetch_projects_returns_null_and_logs_on_request_failure(): void
    {
        $client = $this->clientMocking([
            new RequestException('unauthorized', new Request('GET', '/rest/api/2/project'), new Response(401)),
        ]);

        $this->assertNull($this->service->fetchProjects($client));
    }

    public function test_fetch_tickets_by_project_groups_issues_per_project_key(): void
    {
        $issue = (object) [
            'key' => 'ALP-1',
            'fields' => (object) ['summary' => 'First ticket'],
        ];

        $client = $this->clientMocking([
            new Response(200, [], json_encode(['total' => 1, 'issues' => [$issue]])),
        ]);

        $result = $this->service->fetchTicketsByProject($client, ['ALP']);

        $this->assertSame(1, $result['ALP']['total']);
        $this->assertSame('ALP-1', $result['ALP']['issues'][0]['code']);
        $this->assertSame('First ticket', $result['ALP']['issues'][0]['name']);
    }

    public function test_fetch_tickets_by_project_returns_null_and_logs_on_request_failure(): void
    {
        $client = $this->clientMocking([
            new RequestException('server error', new Request('GET', '/rest/api/2/search'), new Response(500)),
        ]);

        $this->assertNull($this->service->fetchTicketsByProject($client, ['ALP']));
    }
}
