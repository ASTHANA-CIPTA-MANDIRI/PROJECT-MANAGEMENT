<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\JiraImport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Rules\SafeJiraHost;
use App\Services\JiraImportService;
use App\Support\JiraHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Jira host is user input that becomes a Guzzle base_uri, so an
 * unvalidated field turns the import wizard into an SSRF proxy: the server
 * would happily fetch loopback services or the cloud metadata endpoint and
 * reflect the response into the panel. These tests guard the three layers —
 * the guard itself, the form rule, and the Livewire listeners (which are
 * callable directly and therefore never see form validation).
 */
class JiraHostSsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'Import from Jira']);
        $role = Role::create(['name' => 'Jira importer']);
        $role->syncPermissions(['Import from Jira']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh());
    }

    protected function tearDown(): void
    {
        JiraHost::resolveUsing(null);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function internalHosts(): array
    {
        return [
            'loopback' => ['https://127.0.0.1'],
            'loopback with port' => ['https://127.0.0.1:6379'],
            'loopback by name' => ['https://localhost'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'private class A' => ['https://10.0.0.5'],
            'private class B' => ['https://172.16.4.1'],
            'private class C' => ['https://192.168.1.10'],
            'ipv6 loopback' => ['https://[::1]'],
            'ipv6 unique local' => ['https://[fd00::1]'],
            'ipv4 mapped loopback' => ['https://[::ffff:127.0.0.1]'],
        ];
    }

    /**
     * @dataProvider internalHosts
     */
    public function test_internal_addresses_are_refused(string $host): void
    {
        $this->assertFalse(JiraHost::isSafe($host), $host.' should be refused');
    }

    public function test_non_https_and_malformed_hosts_are_refused(): void
    {
        JiraHost::resolveUsing(fn () => ['185.166.143.48']);

        $this->assertFalse(JiraHost::isSafe('http://example.atlassian.net'));
        $this->assertFalse(JiraHost::isSafe('file:///etc/passwd'));
        $this->assertFalse(JiraHost::isSafe('gopher://example.atlassian.net'));
        $this->assertFalse(JiraHost::isSafe('example.atlassian.net'));
        $this->assertFalse(JiraHost::isSafe(''));
        $this->assertFalse(JiraHost::isSafe('https://user:pass@example.atlassian.net'));
    }

    public function test_an_unresolvable_host_is_refused(): void
    {
        JiraHost::resolveUsing(fn () => []);

        $this->assertFalse(JiraHost::isSafe('https://example.atlassian.net'));
    }

    public function test_a_public_https_host_is_accepted_and_reduced_to_its_origin(): void
    {
        JiraHost::resolveUsing(fn () => ['185.166.143.48']);

        $this->assertSame(
            'https://example.atlassian.net',
            JiraHost::sanitize('https://example.atlassian.net/jira?next=/x')
        );
        $this->assertSame(
            'https://jira.example.com:8443',
            JiraHost::sanitize('https://jira.example.com:8443')
        );
    }

    public function test_the_allowlist_restricts_which_public_hosts_may_be_called(): void
    {
        JiraHost::resolveUsing(fn () => ['185.166.143.48']);
        config(['jira.allowed_hosts' => ['*.atlassian.net', 'jira.example.com']]);

        $this->assertTrue(JiraHost::isSafe('https://example.atlassian.net'));
        $this->assertTrue(JiraHost::isSafe('https://jira.example.com'));
        $this->assertFalse(JiraHost::isSafe('https://evil.example.org'));
        $this->assertFalse(JiraHost::isSafe('https://atlassian.net.evil.example.org'));
    }

    public function test_the_form_rule_rejects_an_internal_host(): void
    {
        $rule = ['host' => ['required', new SafeJiraHost]];

        $this->assertTrue(Validator::make(['host' => 'http://169.254.169.254'], $rule)->fails());

        JiraHost::resolveUsing(fn () => ['185.166.143.48']);
        $this->assertFalse(Validator::make(['host' => 'https://example.atlassian.net'], $rule)->fails());
    }

    public function test_the_service_refuses_to_build_a_client_for_an_internal_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(JiraImportService::class)->connect('http://127.0.0.1:6379', 'user', 'token');
    }

    public function test_the_client_is_built_without_redirects_and_with_timeouts(): void
    {
        JiraHost::resolveUsing(fn () => ['185.166.143.48']);

        $config = app(JiraImportService::class)
            ->connect('https://example.atlassian.net', 'user', 'token')
            ->getConfig();

        $this->assertSame('https://example.atlassian.net', (string) $config['base_uri']);
        $this->assertFalse($config['allow_redirects']);
        $this->assertSame((float) config('jira.timeout'), $config['timeout']);
        $this->assertSame((float) config('jira.connect_timeout'), $config['connect_timeout']);
    }

    /**
     * The client must be pinned to the exact address(es) that were resolved
     * and checked against the blocklist, not left to resolve the hostname
     * again on its own - otherwise a rebinding DNS server could pass this
     * check and then serve an internal address for the real connection.
     */
    public function test_the_client_is_pinned_to_the_validated_address(): void
    {
        JiraHost::resolveUsing(fn () => ['185.166.143.48']);

        $config = app(JiraImportService::class)
            ->connect('https://example.atlassian.net:8443', 'user', 'token')
            ->getConfig();

        $this->assertSame(
            ['example.atlassian.net:8443:185.166.143.48'],
            $config['curl'][CURLOPT_RESOLVE]
        );
    }

    public function test_the_client_pins_every_resolved_address(): void
    {
        JiraHost::resolveUsing(fn () => ['185.166.143.48', '2a01:111:200a::1']);

        $config = app(JiraImportService::class)
            ->connect('https://example.atlassian.net', 'user', 'token')
            ->getConfig();

        $this->assertSame(
            [
                'example.atlassian.net:443:185.166.143.48',
                'example.atlassian.net:443:[2a01:111:200a::1]',
            ],
            $config['curl'][CURLOPT_RESOLVE]
        );
    }

    /**
     * Partial mock: connect() runs for real (and must throw), while the fetch
     * methods are the ones that would perform the actual request.
     */
    private function partialService(): JiraImportService
    {
        $service = Mockery::mock(JiraImportService::class)->makePartial();
        $this->app->instance(JiraImportService::class, $service);

        return $service;
    }

    public function test_the_livewire_listener_does_not_call_an_internal_host(): void
    {
        $this->partialService()->shouldReceive('fetchProjects')->never();

        $component = Livewire::test(JiraImport::class)
            ->set('host', 'http://169.254.169.254')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->call('updateJiraProjects')
            ->assertSuccessful();

        $this->assertNull($component->instance()->getProjects());
        $this->assertFalse($component->instance()->isLoadingProjects());
    }

    public function test_the_ticket_listener_does_not_call_an_internal_host(): void
    {
        $this->partialService()->shouldReceive('fetchTicketsByProject')->never();

        $component = Livewire::test(JiraImport::class)
            ->set('host', 'http://127.0.0.1:6379')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->set('selected_projects', ['ALP'])
            ->call('updateJiraTickets')
            ->assertSuccessful();

        $this->assertNull($component->instance()->getTickets());
        $this->assertFalse($component->instance()->isLoadingTickets());
    }

    public function test_importing_from_an_internal_host_dispatches_nothing(): void
    {
        Queue::fake();

        Livewire::test(JiraImport::class)
            ->set('host', 'http://169.254.169.254')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->set('data', ['alp_alp_1' => true])
            ->set('ticketsDataApi', ['alp_alp_1' => 'http://169.254.169.254/rest/api/2/issue/10001'])
            ->call('import')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
