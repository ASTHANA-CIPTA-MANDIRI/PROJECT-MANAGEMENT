<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Forms\JiraImportForm;
use App\Jobs\ImportJiraTicketsJob;
use App\Services\JiraImportService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class JiraImport extends AuthorizedPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $permission = 'Import from Jira';

    protected static ?string $navigationIcon = 'heroicon-o-cloud-download';

    protected static string $view = 'filament.pages.jira-import';

    protected static ?string $slug = 'jira-import';

    protected static ?int $navigationSort = 2;

    protected $listeners = [
        'updateJiraProjects',
        'updateJiraTickets',
    ];

    public $host;

    public $username;

    public $token;

    private $loadingProjects;

    private $projects;

    public $selected_projects;

    private $loadingTickets;

    private $tickets;

    public $selected_tickets;

    public $data = [];

    public $ticketsDataApi;

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getSubheading(): string|Htmlable|null
    {
        return __('Use this section to login into your jira account and import tickets to this application');
    }

    protected static function getNavigationLabel(): string
    {
        return __('Jira import');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    protected function getFormSchema(): array
    {
        return JiraImportForm::schema($this);
    }

    public function isLoadingProjects(): bool
    {
        return (bool) $this->loadingProjects;
    }

    public function getProjects(): ?array
    {
        return $this->projects;
    }

    public function isLoadingTickets(): bool
    {
        return (bool) $this->loadingTickets;
    }

    public function getTickets(): ?array
    {
        return $this->tickets;
    }

    public function beginLoadingProjects(): void
    {
        $this->loadingProjects = true;
        $this->emit('updateJiraProjects');
    }

    public function beginLoadingTickets(): void
    {
        $this->loadingTickets = true;
        $this->emit('updateJiraTickets');
    }

    public function import(JiraImportService $jira): void
    {
        if ($this->data && count($this->data)) {
            $tickets = [];
            try {
                foreach (array_keys($this->data) as $item) {
                    $url = $this->ticketsDataApi[$item];
                    $tickets[] = $jira->fetchTicketDetails($this->host, $this->username, $this->token, $url);
                }
            } catch (InvalidArgumentException $e) {
                $this->notify('danger', $e->getMessage());

                return;
            }
            dispatch(new ImportJiraTicketsJob($tickets, auth()->user()));
            $this->notify('success', __('The importation job is started, when finished you will be notified'), true);
            $this->redirect(route('filament.pages.jira-import'));
        } else {
            $this->notify('warning', __('Please choose at least a jira ticket to import'));
        }
    }

    public function updateJiraProjects(JiraImportService $jira): void
    {
        $this->loadingProjects = false;

        try {
            $client = $jira->connect($this->host, $this->username, $this->token);
        } catch (InvalidArgumentException $e) {
            $this->projects = null;
            $this->notify('danger', $e->getMessage());

            return;
        }

        $this->projects = $jira->fetchProjects($client);
    }

    public function updateJiraTickets(JiraImportService $jira): void
    {
        $this->ticketsDataApi = [];
        $this->loadingTickets = false;

        try {
            $client = $jira->connect($this->host, $this->username, $this->token);
        } catch (InvalidArgumentException $e) {
            $this->tickets = null;
            $this->notify('danger', $e->getMessage());

            return;
        }

        $this->tickets = $jira->fetchTicketsByProject($client, $this->selected_projects);
        foreach ($this->tickets ?? [] as $projectKey => $ticket) {
            foreach ($ticket['issues'] as $issue) {
                if (! isset($issue['data']->self)) {
                    continue;
                }
                $this->ticketsDataApi[Str::slug($projectKey).'_'.Str::slug($issue['code'])] = $issue['data']->self;
            }
        }
        $this->loadingTickets = false;
    }
}
