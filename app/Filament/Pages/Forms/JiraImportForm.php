<?php

namespace App\Filament\Pages\Forms;

use App\Filament\Pages\JiraImport;
use App\Rules\SafeJiraHost;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The three-step wizard (login, pick projects, pick tickets) for the
 * JiraImport page, extracted for readability. Steps read the page's loaded
 * projects/tickets and trigger its `updateJiraProjects`/`updateJiraTickets`
 * listeners between steps, so each step needs a live reference to $page
 * rather than a snapshot of its state.
 *
 * Project and issue labels are built as raw HtmlString, and every value in
 * them comes from the remote Jira server — whose address the user picks — so
 * each one must be passed through e() before it is concatenated.
 */
class JiraImportForm
{
    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function schema(JiraImport $page): array
    {
        return [
            Card::make()
                ->schema([
                    Wizard::make([
                        self::loginStep($page),
                        self::projectsStep($page),
                        self::ticketsStep($page),
                    ])
                        ->submitAction(new HtmlString("<button type='submit' class='px-3 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded'>".__('Import').'</button>')),
                ]),
        ];
    }

    private static function loginStep(JiraImport $page): Wizard\Step
    {
        return Wizard\Step::make(__('Jira login'))
            ->schema([
                Placeholder::make('info')
                    ->extraAttributes([
                        'class' => 'bg-primary-500 rounded-lg border border-primary-600 text-white font-medium text-sm py-3 px-4',
                    ])
                    ->disableLabel()
                    ->content(__('Important: Your jira credentials are only used to communicate with jira REST API, and will not be stored in this application')),

                Grid::make()
                    ->schema([
                        TextInput::make('host')
                            ->label(__('Host'))
                            ->helperText(__('The https url used to access your jira account, e.g. https://your-team.atlassian.net'))
                            ->url()
                            ->required()
                            ->rule(new SafeJiraHost),

                        TextInput::make('username')
                            ->label(__('Username'))
                            ->helperText(__('Your jira account username'))
                            ->required(),

                        TextInput::make('token')
                            ->label(__('API Token'))
                            ->helperText(__('Your jira account API Token'))
                            ->password()
                            ->required(),
                    ]),
            ])
            ->afterValidation(fn () => $page->beginLoadingProjects());
    }

    private static function projectsStep(JiraImport $page): Wizard\Step
    {
        return Wizard\Step::make(__('Jira projects'))
            ->schema([
                Placeholder::make('hint')
                    ->extraAttributes([
                        'class' => 'bg-primary-500 rounded-lg border border-primary-600 text-white font-medium text-sm py-3 px-4',
                    ])
                    ->disableLabel()
                    ->visible(fn () => ! $page->isLoadingProjects() && $page->getProjects())
                    ->content(__('Choose your jira projects to import')),

                Placeholder::make('loading')
                    ->extraAttributes([
                        'class' => 'bg-warning-500 rounded-lg border border-warning-600 text-white font-medium text-sm py-3 px-4',
                    ])
                    ->disableLabel()
                    ->visible(fn () => $page->isLoadingProjects())
                    ->content(__('Loading projects, please wait...')),

                Placeholder::make('info')
                    ->extraAttributes([
                        'class' => 'bg-danger-500 rounded-lg border border-danger-600 text-white font-medium text-sm py-3 px-4',
                    ])
                    ->disableLabel()
                    ->visible(fn () => ! $page->isLoadingProjects() && ! $page->getProjects())
                    ->content(__('Your jira credentials are incorrect, please go to previous step and re-enter your jira credentials')),

                CheckboxList::make('selected_projects')
                    ->label(__('Jira projects'))
                    ->required()
                    ->visible(fn () => $page->getProjects())
                    ->options(function () use ($page) {
                        $list = [];
                        foreach ($page->getProjects() ?? [] as $project) {
                            $avatar = self::avatarUrl($project);

                            $list[$project->key] = new HtmlString(
                                "<div class='w-full flex flex-col gap-1'>"
                                ."<div class='w-full flex items-center gap-1'>"
                                .($avatar ? "<img src='".e($avatar)."' class='rounded-full w-8 h-8 shadow' />" : '')
                                ."<span class='font-medium text-gray-700 text-base'>".e($project->name).'</span>'
                                ."<div class='text-gray-700 text-xs font-light'><span class='font-medium uppercase'>/</span> ".e($project->key).'</div>'
                                .'</div>'
                                .'</div>'
                            );
                        }

                        return $list;
                    }),

            ])
            ->afterValidation(fn () => $page->beginLoadingTickets());
    }

    /**
     * The avatar url comes from the remote Jira server and lands in an `src`
     * attribute, so only render it when it is a plain https url — that keeps
     * javascript:/data: payloads out even though the value is escaped.
     */
    private static function avatarUrl($project): ?string
    {
        $url = $project->avatarUrls->{'16x16'} ?? null;

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    private static function ticketsStep(JiraImport $page): Wizard\Step
    {
        return Wizard\Step::make(__('Jira tickets'))
            ->schema(function () use ($page) {
                $fields = [];

                $fields[] = Placeholder::make('hint')
                    ->extraAttributes([
                        'class' => 'bg-primary-500 rounded-lg border border-primary-600 text-white font-medium text-sm py-3 px-4',
                    ])
                    ->disableLabel()
                    ->visible(fn () => ! $page->isLoadingTickets() && $page->getTickets())
                    ->content(__('Choose your jira projects to import'));

                $fields[] = Placeholder::make('loading')
                    ->extraAttributes([
                        'class' => 'bg-warning-500 rounded-lg border border-warning-600 text-white font-medium text-sm py-3 px-4',
                    ])
                    ->disableLabel()
                    ->visible(fn () => $page->isLoadingTickets())
                    ->content(__('Loading tickets, please wait...'));

                if (! $page->isLoadingTickets()) {
                    if ($tickets = $page->getTickets()) {
                        foreach ($tickets as $projectKey => $ticket) {
                            if ($ticket['total'] > 0) {
                                $fields[] = Placeholder::make('tickets_'.Str::slug($projectKey))
                                    ->label(__('Tickets for the project:').' '.$projectKey)
                                    ->extraAttributes([
                                        'style' => 'margin-bottom: -15px;',
                                    ])
                                    ->content('');

                                foreach ($ticket['issues'] as $issue) {
                                    $fields[] = Checkbox::make('data.'.Str::slug($projectKey).'_'.Str::slug($issue['code']))
                                        ->label(fn () => new HtmlString(
                                            "<div class='w-full flex flex-col gap-1'>"
                                            ."<div class='w-full flex items-center gap-1'>"
                                            ."<div class='text-gray-700 text-xs font-light'><span class='font-medium uppercase'>".e($issue['code']).'</span> '.e($issue['name']).'</div>'
                                            .'</div>'
                                            .'</div>'
                                        ));
                                }
                            } else {
                                $fields[] = Placeholder::make('no_tickets_'.Str::slug($projectKey))
                                    ->label(__('Tickets for the project:').' '.$projectKey)
                                    ->content(__('No tickets found!'));
                            }
                        }
                    } else {
                        $fields[] = Placeholder::make('info')
                            ->extraAttributes([
                                'class' => 'bg-warning-500 rounded-lg border border-warning-600 text-white font-medium text-sm py-3 px-4',
                            ])
                            ->disableLabel()
                            ->visible(fn () => ! $page->getProjects())
                            ->content(__('No tickets found!'));
                    }
                }

                return $fields;
            });
    }
}
