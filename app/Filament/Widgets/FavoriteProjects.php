<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\Ticket;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

class FavoriteProjects extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 12,
    ];

    protected function getColumns(): int
    {
        return 4;
    }

    public static function canView(): bool
    {
        return auth()->user()->can('List projects');
    }

    protected function getCards(): array
    {
        // Audit finding (pre-Fase 7): favoriting a project writes a plain
        // project_favorites pivot row that outlives the access it was
        // favorited under - a revoked project membership, an organization
        // switch, or the organization's trial ending none of them clear it.
        // ->accessibleBy() (the same scope every other project listing in
        // this app uses) re-checks real access on every render instead of
        // trusting that a favorite still means "can see this project".
        //
        // Eager load everything the cards read (ticket count, contributors via
        // users+owner, and the cover media) to avoid a query per project.
        $favoriteProjects = auth()->user()->favoriteProjects()
            ->accessibleBy(auth()->user())
            ->withCount('tickets')
            ->with(['users', 'owner', 'media'])
            ->get();

        $cards = $this->summaryCards();
        foreach ($favoriteProjects as $project) {
            $ticketsCount = $project->tickets_count;
            $contributorsCount = $project->contributors->count();
            $cards[] = Card::make('', new HtmlString('
                    <div class="flex items-center gap-2 -mt-2 text-lg">
                        <div style=\'background-image: url("'.e($project->cover).'")\'
                             class="w-8 h-8 bg-cover bg-center bg-no-repeat"></div>
                        <span>'.e($project->name).'</span>
                    </div>
                '))
                ->color('success')
                ->extraAttributes([
                    'class' => 'fav-project-card hover:shadow-lg',
                ])
                ->description(new HtmlString('
                        <div class="w-full flex items-center gap-2 mt-2 text-gray-500 font-normal">'
                            .$ticketsCount
                            .' '
                            .__($ticketsCount > 1 ? 'Tickets' : 'Ticket')
                            .' '
                            .__('and')
                            .' '
                            .$contributorsCount
                            .' '
                            .__($contributorsCount > 1 ? 'Contributors' : 'Contributor')
                        .'</div>
                        <div class="text-xs w-full flex items-center gap-2 mt-2">
                            <a class="text-primary-400 hover:text-primary-500 hover:cursor-pointer"
                               href="'.route('filament.resources.projects.view', $project).'">
                                '.__('View details').'
                            </a>
                            <span class="text-gray-300">|</span>
                            <a class="text-primary-400 hover:text-primary-500 hover:cursor-pointer"
                               href="'.route('filament.pages.kanban/{project}', ['project' => $project->id]).'">
                                '.__('Tickets').'
                            </a>
                        </div>
                    '));
        }

        return $cards;
    }

    /**
     * Top row of the dashboard: one welcome card plus the headline KPI cards.
     * Every count goes through the same access scopes the listings use
     * (accessibleBy / visibleTo), so a user never sees a total that includes
     * records they cannot open.
     *
     * @return array<int, Card>
     */
    private function summaryCards(): array
    {
        $user = auth()->user();
        $canListTickets = $user->can('List tickets');
        $projectsCount = Project::query()->accessibleBy($user)->count();

        $openTickets = fn () => Ticket::query()->visibleTo($user)
            ->whereHas('status', fn ($query) => $query->where('is_final', false));
        $myActive = $canListTickets
            ? $openTickets()->where('responsible_id', $user->id)->count()
            : 0;

        $cards = [$this->welcomeCard($user, $canListTickets, $myActive, $projectsCount)];

        if ($canListTickets) {
            $kpis = [
                Card::make(__('Active tickets'), $openTickets()->count())->icon('heroicon-o-ticket'),
                Card::make(__('My active tickets'), $myActive)->icon('heroicon-o-user'),
                Card::make(__('Completed tickets'), Ticket::query()->visibleTo($user)
                    ->whereHas('status', fn ($query) => $query->where('is_final', true))->count())
                    ->icon('heroicon-o-check-circle'),
            ];

            foreach ($kpis as $index => $card) {
                $cards[] = $card->extraAttributes(['class' => 'kpi-card kpi-card-'.($index + 1)]);
            }
        }

        return $cards;
    }

    private function welcomeCard($user, bool $canListTickets, int $myActive, int $projectsCount): Card
    {
        $summary = $canListTickets
            ? __('You have :tickets active tickets across :projects projects', [
                'tickets' => $myActive,
                'projects' => $projectsCount,
            ])
            : __('You have access to :projects projects', ['projects' => $projectsCount]);

        $button = '';
        if ($canListTickets && Route::has('filament.resources.tickets.index')) {
            $button = '<a href="'.e(route('filament.resources.tickets.index')).'" class="welcome-card__button">'
                .e(__('View my tickets')).'</a>';
        }

        return Card::make('', new HtmlString('
                <div class="welcome-card__body">
                    <img src="'.e(Filament::getUserAvatarUrl($user)).'" alt="" class="welcome-card__avatar">
                    <div class="welcome-card__text">
                        <div class="welcome-card__title">'.e(__($this->greeting()).', '.$user->name).'</div>
                        <p class="welcome-card__summary">'.e($summary).'</p>
                        '.$button.'
                    </div>
                </div>
            '))
            ->extraAttributes([
                'class' => 'welcome-card'.($canListTickets ? '' : ' welcome-card--wide'),
            ]);
    }

    /**
     * Time-of-day greeting key, based on the application timezone
     * (config app.timezone): 05-10 morning, 11-14 afternoon, 15-17 evening,
     * 18-04 night.
     */
    private function greeting(): string
    {
        $hour = (int) now(config('app.display_timezone'))->format('G');

        return match (true) {
            $hour >= 5 && $hour < 11 => 'Good morning',
            $hour >= 11 && $hour < 15 => 'Good afternoon',
            $hour >= 15 && $hour < 18 => 'Good evening',
            default => 'Good night',
        };
    }
}
