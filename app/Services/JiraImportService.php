<?php

namespace App\Services;

use App\Support\JiraHost;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the Jira REST API for the JiraImport page/job. Credentials are
 * never stored — each call builds a fresh client from the host/username/token
 * the user just entered. The host is user input, so it goes through
 * App\Support\JiraHost before it can become a base uri.
 */
class JiraImportService
{
    /**
     * @throws \InvalidArgumentException when the host is not allowed to be called.
     */
    public function connect(string $host, string $username, string $token): Client
    {
        $validated = JiraHost::validate($host);

        return new Client([
            'base_uri' => $validated['origin'],
            'allow_redirects' => false,
            'timeout' => (float) config('jira.timeout', 10),
            'connect_timeout' => (float) config('jira.connect_timeout', 5),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($username.':'.$token),
            ],
            // Pin the connection to the address(es) already checked above,
            // instead of letting curl resolve the hostname again on its own
            // right before connecting - that second, unchecked lookup is
            // what a DNS-rebinding attack would target.
            'curl' => [
                CURLOPT_RESOLVE => array_map(
                    fn (string $ip) => $validated['hostname'].':'.$validated['port'].':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip),
                    $validated['addresses']
                ),
            ],
        ]);
    }

    public function fetchProjects(Client $client): ?array
    {
        try {
            $response = $client->get('/rest/api/2/project');
            $data = json_decode($response->getBody()->getContents());

            if (! is_array($data)) {
                return null;
            }

            // The wizard renders ->key/->name/->avatarUrls for every entry, so
            // drop anything that does not carry them.
            return array_values(array_filter(
                $data,
                fn ($project) => is_object($project) && isset($project->key, $project->name)
            ));
        } catch (GuzzleException $e) {
            Log::error($e->getTraceAsString());

            return null;
        }
    }

    public function fetchTicketsByProject(Client $client, array $projectKeys): ?array
    {
        try {
            $formatIssues = function ($issues) {
                $results = [];
                foreach ($issues as $issue) {
                    if (! isset($issue->key)) {
                        continue;
                    }
                    $results[] = [
                        'code' => (string) $issue->key,
                        'name' => (string) ($issue->fields->summary ?? $issue->key),
                        'data' => $issue,
                    ];
                }

                return $results;
            };
            $results = [];
            foreach ($projectKeys as $projectKey) {
                if (! self::isValidKey($projectKey)) {
                    Log::warning('Skipped a Jira project with an unexpected key.');

                    continue;
                }

                // Let Guzzle encode the JQL, and quote the key so it cannot
                // extend the query into extra clauses.
                $response = $client->get('/rest/api/2/search', [
                    'query' => ['jql' => 'project = "'.$projectKey.'"'],
                ]);
                $data = json_decode($response->getBody()->getContents());
                $issues = is_object($data) && is_array($data->issues ?? null) ? $data->issues : [];

                $results[$projectKey] = [
                    'total' => (int) ($data->total ?? count($issues)),
                    'issues' => $formatIssues($issues),
                ];
            }

            return $results;
        } catch (GuzzleException $e) {
            Log::error($e->getTraceAsString());

            return null;
        }
    }

    public function fetchTicketDetails(string $host, string $username, string $token, string $url)
    {
        $key = self::issueKeyFromUrl($url);

        if ($key === null) {
            Log::warning('Refused to fetch a Jira issue from an unexpected url.');

            return null;
        }

        try {
            $client = $this->connect($host, $username, $token);
            $response = $client->get('/rest/api/2/issue/'.rawurlencode($key));

            return json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            Log::error($e->getTraceAsString());

            return null;
        }
    }

    /**
     * Pull the issue id/key out of a `self` link returned by Jira. That link
     * comes from the remote server, so only the last path segment is used and
     * it must still look like an issue key — never a path or query of its own.
     */
    private static function issueKeyFromUrl(string $url): ?string
    {
        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $segments = explode('/', rtrim($path, '/'));
        $key = end($segments);

        return self::isValidKey($key) ? $key : null;
    }

    /**
     * Jira project keys and issue keys are alphanumeric, optionally with a
     * `-<number>` suffix (ALP-12); `self` links may end in a numeric id.
     */
    private static function isValidKey($key): bool
    {
        return is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_]*(-\d+)?$/', $key) === 1;
    }
}
