<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the Jira REST API for the JiraImport page/job. Credentials are
 * never stored — each call builds a fresh client from the host/username/token
 * the user just entered.
 */
class JiraImportService
{
    public function connect(string $host, string $username, string $token): Client
    {
        return new Client([
            'base_uri' => $host,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($username.':'.$token),
            ],
        ]);
    }

    public function fetchProjects(Client $client): ?array
    {
        try {
            $response = $client->get('/rest/api/2/project');

            return json_decode($response->getBody()->getContents());
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
                    $results[] = [
                        'code' => $issue->key,
                        'name' => $issue->fields->summary,
                        'data' => $issue,
                    ];
                }

                return $results;
            };
            $results = [];
            foreach ($projectKeys as $projectKey) {
                $response = $client->get('/rest/api/2/search?jql=project='.$projectKey);
                $data = json_decode($response->getBody()->getContents());
                $results[$projectKey] = [
                    'total' => $data->total,
                    'issues' => $formatIssues($data->issues),
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
        try {
            $client = $this->connect($host, $username, $token);
            $urlParts = explode('/', $url);
            $response = $client->get('/rest/api/2/issue/'.$urlParts[count($urlParts) - 1]);

            return json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            Log::error($e->getTraceAsString());

            return null;
        }
    }
}
