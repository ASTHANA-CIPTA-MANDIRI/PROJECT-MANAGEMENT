<?php

namespace Database\Seeders;

use App\Models\Epic;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Support\OrganizationDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 5.5 (Step 2.5) — development/manual-test fixtures for Organization
 * RBAC (Phase 4/5/5.1) and, since the Fase 3B/6/6b revamp below, real
 * sample Projects, Epics, Sprints and Tickets that make the RBAC matrix
 * directly visible instead of something you have to build yourself to see
 * in action. Each demo project gets enough spread-out Ticket statuses to
 * look like a real Kanban board, plus dated Epics and an active Sprint so
 * the Roadmap (Gantt) page has something to draw too — a Project with a
 * single empty Ticket doesn't exercise either page. NOT called by
 * DatabaseSeeder and never runs automatically: invoke explicitly with
 * `php artisan db:seed --class=OrganizationDemoSeeder`.
 *
 * Idempotent by design, mirroring DefaultUserSeeder's conventions:
 * - Users are firstOrCreate()'d by email, so re-running never duplicates them.
 * - Organizations are firstOrCreate()'d by name (organizations.name already
 *   has a unique index — see 2026_09_02_000001_create_organizations_table.php
 *   — so this can never produce a duplicate at the database level either).
 * - Memberships use syncWithoutDetaching() keyed on (organization, user),
 *   which — unlike DefaultUserSeeder's "never touch a role an admin already
 *   changed by hand" stance for real accounts — deliberately DOES update the
 *   role back to the documented matrix below on every re-run. This is
 *   throwaway RBAC test data: the point of re-seeding is to reset it to a
 *   known state after manually testing role changes, not to preserve
 *   whatever got changed while poking at the UI.
 * - Projects are looked up by their (globally unique) ticket_prefix before
 *   creating, and Tickets by (project_id, name) — same "reset to known
 *   state on re-run" reasoning.
 *
 * Refuses to run in production outright (not just per-user like
 * DefaultUserSeeder's demo account): every user, organization, project and
 * ticket this seeder creates is disposable test data, so there is no
 * legitimate reason for any part of it to exist outside development/local/
 * testing.
 *
 * ---------------------------------------------------------------------
 * THE ONE THING THIS SEEDER EXISTS TO MAKE OBVIOUS: an Organization does
 * NOT automatically open up every Project inside it to every member.
 * ---------------------------------------------------------------------
 * Only Owner/Admin get that for free (they manage the whole Organization).
 * A plain Member only sees a Project if they were explicitly added to it
 * (project_users) — same as being owner_id of it. This is Project::
 * isAccessibleBy()'s "no bypass" rule, deliberately tested in ADR 0001
 * ("Does an Organization-level permission bypass Project-level membership?
 * No bypass — passed"). "Organization Alpha - Internal HR Tool" below is
 * built specifically to demonstrate this: member@example.test can log in,
 * be a real member of Alpha, and still not see it — that is the CORRECT
 * result, not a bug. Run `php artisan db:seed --class=OrganizationDemoSeeder`
 * and read the table it prints at the end for the full breakdown.
 *
 * ---------------------------------------------------------------------
 * SECOND THING WORTH KNOWING: a real Organization switcher exists.
 * ---------------------------------------------------------------------
 * App\Http\Livewire\OrganizationSwitcher (Phase 3C), rendered at the top
 * of every Filament page's sidebar via the `sidebar.start` render hook
 * (AppServiceProvider::configureOrganizationSwitcher()) - a dropdown of
 * every Organization the logged-in user belongs to. A user with only one
 * membership just sees that name as plain text (no dropdown needed).
 * OrganizationContext::current() only picks the FIRST membership as a
 * *fallback* for when nothing has been explicitly selected yet - once a
 * demo account uses the switcher once, every subsequent page reflects
 * that choice until they switch again. This is why owner@example.test
 * owning both Alpha and Delta, and multi@example.test owning both Beta
 * and Gamma, needs no special handling: just log in and use the
 * dropdown to reach the other one.
 */
class OrganizationDemoSeeder extends Seeder
{
    /**
     * DEVELOPMENT / LOCAL ONLY. Every account this seeder creates shares
     * this password — never used for the real admin/user accounts
     * DefaultUserSeeder creates, and never valid in production since this
     * class refuses to run there at all.
     */
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error(
                'OrganizationDemoSeeder refuses to run in production - it only ever creates disposable RBAC test data.'
            );

            return;
        }

        // Every "default"-status project needs at least one global TicketStatus
        // to exist (project_id null, is_default true) - firstOrCreate()'d by
        // name, so calling this here is a no-op if DatabaseSeeder already ran.
        $this->call(TicketStatusSeeder::class);

        $owner = $this->user('owner@example.test', 'Test Owner');
        $admin = $this->user('admin@example.test', 'Test Admin');
        $member = $this->user('member@example.test', 'Test Member');
        $multi = $this->user('multi@example.test', 'Test Multi Organization User');
        // Deliberately given no membership below - the "0 organizations" /
        // "no automatic organization" manual test case.
        $noOrg = $this->user('noorg@example.test', 'Test No Organization User');

        $alpha = Organization::firstOrCreate(['name' => 'Organization Alpha']);
        $beta = Organization::firstOrCreate(['name' => 'Organization Beta']);
        $gamma = Organization::firstOrCreate(['name' => 'Organization Gamma']);
        // A fourth organization with a single member (its owner) - the
        // "newly created organization with only its owner, no teammates
        // invited yet" manual test case. It still gets its own project below
        // (a solo Owner exploring the product before inviting anyone is a
        // realistic state, not an empty one) - what stays deliberately
        // minimal is the *membership*, not the work.
        $delta = Organization::firstOrCreate(['name' => 'Organization Delta']);

        $this->membership($alpha, $owner, 'owner');
        $this->membership($alpha, $admin, 'admin');
        $this->membership($alpha, $member, 'member');
        $this->membership($alpha, $multi, 'member');

        $this->membership($beta, $multi, 'owner');
        $this->membership($beta, $admin, 'member');

        $this->membership($gamma, $multi, 'owner');
        $this->membership($gamma, $member, 'member');

        $this->membership($delta, $owner, 'owner');

        // Fase 3B: each demo Organization gets its own real starter set of
        // ticket types/priorities/project statuses/activities, exactly like
        // a real paying tenant would on signup - not the legacy global
        // fallback. Guarded so re-running this seeder never duplicates them.
        $this->seedOrganizationDefaultsOnce($alpha);
        $this->seedOrganizationDefaultsOnce($beta);
        $this->seedOrganizationDefaultsOnce($gamma);
        $this->seedOrganizationDefaultsOnce($delta);

        $visibleToMember = $this->project(
            $alpha,
            name: 'Alpha - Company Website',
            ticketPrefix: 'AWS',
            owner: $owner,
        );
        $this->addProjectMember($visibleToMember, $member);

        $hiddenFromMember = $this->project(
            $alpha,
            name: 'Alpha - Internal HR Tool',
            ticketPrefix: 'AHR',
            owner: $owner,
        );
        // Deliberately NOT added as a member - this is the exact case that
        // makes "why can't member@example.test see this project" obvious.

        $betaProject = $this->project(
            $beta,
            name: 'Beta - Mobile App Revamp',
            ticketPrefix: 'BMA',
            owner: $multi,
            type: 'scrum',
        );

        // multi@example.test's second Owner-ed project, on the Organization
        // it reaches via the switcher (see "SECOND THING" in the class
        // docblock). member@example.test is a plain 'member' of Gamma at
        // the Organization level (see membership() above) and, unlike Alpha's
        // "Internal HR Tool", IS added as a project member here - the mirror
        // image of the Alpha case: same account, same Organization role,
        // opposite visibility outcome, purely because of explicit project
        // membership either way. That contrast is the point.
        $gammaProject = $this->project(
            $gamma,
            name: 'Gamma - Aplikasi Manajemen Inventori',
            ticketPrefix: 'GMI',
            owner: $multi,
        );
        $this->addProjectMember($gammaProject, $member);

        // owner@example.test's second Owner-ed project, reached the same way
        // via the switcher. Deliberately no other project member: Delta only
        // has one Organization member at all (see the comment above its
        // firstOrCreate() call), so there is nobody else to add yet - a solo
        // Owner's own internal tooling project is exactly what a brand-new
        // Organization realistically looks like before its first invite.
        $deltaProject = $this->project(
            $delta,
            name: 'Delta - Dashboard Analitik Internal',
            ticketPrefix: 'DAI',
            owner: $owner,
        );

        $this->populateCompanyWebsite($visibleToMember, $owner, $member);
        $this->populateHrTool($hiddenFromMember, $owner);
        $this->populateMobileApp($betaProject, $multi);
        $this->populateInventoryApp($gammaProject, $multi, $member);
        $this->populateInternalDashboard($deltaProject, $owner);

        $this->printCheatSheet();
    }

    /**
     * The flagship demo project: explicitly visible to member@example.test
     * (see run()), so it also gets the fullest board - two Epics spanning
     * different date ranges (Roadmap needs at least one to draw anything at
     * all), an active Sprint (Project::currentSprint - started_at set,
     * ended_at null - is what the Kanban/Scrum board actually queries by),
     * and Tickets spread across every TicketStatus column.
     */
    private function populateCompanyWebsite(Project $project, User $owner, User $member): void
    {
        $redesign = $this->epic($project, 'Desain Ulang Halaman Utama', now(), now()->addWeeks(3));
        $migration = $this->epic($project, 'Migrasi Infrastruktur Server', now()->addWeeks(3), now()->addWeeks(6));
        $sprint = $this->sprint($project, 'Sprint 1', startedAt: now()->subDays(2));

        $this->ticket($project, 'Contoh tugas - project ini KELIHATAN oleh member@example.test');
        $this->ticket($project, 'Desain wireframe halaman utama', status: 'Done', epic: $redesign, sprint: $sprint, responsible: $owner, estimation: 8);
        $this->ticket($project, 'Implementasi navbar & footer baru', status: 'In progress', epic: $redesign, sprint: $sprint, responsible: $member, estimation: 5);
        $this->ticket($project, 'Uji tampilan responsif di HP', status: 'Todo', epic: $redesign, sprint: $sprint, responsible: $member, estimation: 3);
        $this->ticket($project, 'Setup server staging baru', status: 'Todo', epic: $migration, responsible: $owner, estimation: 6);
    }

    /**
     * Deliberately lighter than Company Website above - the point of this
     * project is "member@example.test cannot see it", not being the most
     * fleshed-out demo. Still gets one dated Epic + a couple Tickets so
     * admin@example.test (who CAN see it) has something to look at too.
     */
    private function populateHrTool(Project $project, User $owner): void
    {
        $leaveModule = $this->epic($project, 'Modul Pengajuan Cuti Karyawan', now(), now()->addWeeks(4));

        $this->ticket($project, 'Contoh tugas - project ini TIDAK kelihatan oleh member@example.test');
        $this->ticket($project, 'Rancang form pengajuan cuti', status: 'In progress', epic: $leaveModule, responsible: $owner, estimation: 4);
        $this->ticket($project, 'Validasi sisa cuti otomatis', status: 'Todo', epic: $leaveModule, responsible: $owner, estimation: 5);
    }

    private function populateMobileApp(Project $project, User $owner): void
    {
        $uiRevamp = $this->epic($project, 'Desain Ulang UI Aplikasi', now(), now()->addWeeks(5));
        $sprint = $this->sprint($project, 'Sprint 1', startedAt: now()->subDays(1));

        $this->ticket($project, 'Contoh tugas di Organization Beta');
        $this->ticket($project, 'Desain ulang halaman login', status: 'Done', epic: $uiRevamp, sprint: $sprint, responsible: $owner, estimation: 5);
        $this->ticket($project, 'Implementasi dark mode', status: 'In progress', epic: $uiRevamp, sprint: $sprint, responsible: $owner, estimation: 6);
        $this->ticket($project, 'Optimasi waktu loading splash screen', status: 'Todo', epic: $uiRevamp, sprint: $sprint, responsible: $owner, estimation: 3);
    }

    /**
     * multi@example.test's second project (Gamma), as fully fleshed out as
     * Company Website / Mobile App Revamp - see the "mirror image of the
     * Alpha case" comment in run() for why member@example.test is a project
     * member here despite not seeing Alpha's HR Tool.
     */
    private function populateInventoryApp(Project $project, User $owner, User $member): void
    {
        $stockTracking = $this->epic($project, 'Modul Pelacakan Stok Real-time', now(), now()->addWeeks(5));
        $sprint = $this->sprint($project, 'Sprint 1', startedAt: now()->subDays(3));

        $this->ticket($project, 'Contoh tugas di Organization Gamma');
        $this->ticket($project, 'Desain skema database produk & stok', status: 'Done', epic: $stockTracking, sprint: $sprint, responsible: $owner, estimation: 6);
        $this->ticket($project, 'Implementasi fitur pemindaian barcode', status: 'In progress', epic: $stockTracking, sprint: $sprint, responsible: $owner, estimation: 8);
        $this->ticket($project, 'Uji integrasi dengan sistem kasir (POS)', status: 'Todo', epic: $stockTracking, sprint: $sprint, responsible: $member, estimation: 4);
    }

    /**
     * owner@example.test's second project (Delta) - Delta's Organization
     * membership stays deliberately minimal (only its Owner - see the
     * comment above its firstOrCreate() call), but that is a statement about
     * team size, not about work existing: this gives Delta the same
     * Epic + active Sprint + spread-of-statuses shape as every other demo
     * project, worked solo by owner@example.test.
     */
    private function populateInternalDashboard(Project $project, User $owner): void
    {
        $usageReporting = $this->epic($project, 'Modul Laporan Penggunaan Fitur', now(), now()->addWeeks(4));
        $sprint = $this->sprint($project, 'Sprint 1', startedAt: now()->subDay());

        $this->ticket($project, 'Contoh tugas di Organization Delta');
        $this->ticket($project, 'Rancang skema tabel event tracking', status: 'Done', epic: $usageReporting, sprint: $sprint, responsible: $owner, estimation: 5);
        $this->ticket($project, 'Implementasi dashboard ringkasan mingguan', status: 'In progress', epic: $usageReporting, sprint: $sprint, responsible: $owner, estimation: 7);
        $this->ticket($project, 'Tambahkan filter rentang tanggal pada laporan', status: 'Todo', epic: $usageReporting, sprint: $sprint, responsible: $owner, estimation: 3);
    }

    /**
     * Also granted the existing "Employee" role (when it exists - i.e. when
     * EmployeeRoleSeeder has already run) purely so these accounts can open
     * the Filament panel at all: User::canAccessFilament() requires holding
     * at least one role, entirely independent of Organization membership.
     * Skipped gracefully otherwise, the same defensive pattern
     * DefaultUserSeeder already uses for its own accounts.
     *
     * Deliberately does NOT grant 'Update project'/'Delete project' beyond
     * what "Employee" already includes - owner@example.test can still
     * update/delete every Alpha project purely through Organization Owner
     * authority (Project::isOwnerManageableThroughOrganizationBy()), which
     * is the realistic path a real Owner account would use too. If this
     * seeder silently added extra permissions on top, the demo would stop
     * reflecting what a real account actually experiences.
     */
    private function user(string $email, string $name): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        if ($role = Role::where('name', 'Employee')->first()) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    private function membership(Organization $organization, User $user, string $role): void
    {
        $organization->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }

    private function seedOrganizationDefaultsOnce(Organization $organization): void
    {
        if (TicketType::where('organization_id', $organization->id)->exists()) {
            return;
        }

        OrganizationDefaults::seed($organization);
    }

    private function project(
        Organization $organization,
        string $name,
        string $ticketPrefix,
        User $owner,
        string $type = 'kanban',
    ): Project {
        $project = Project::where('ticket_prefix', $ticketPrefix)->first();

        if ($project) {
            return $project;
        }

        $status = ProjectStatus::where('organization_id', $organization->id)
            ->where('is_default', true)
            ->firstOrFail();

        // organization_id is deliberately not mass-assignable on Project (see
        // ProjectObserver::creating()/updating() - it is only ever stamped
        // from an authenticated request's OrganizationContext, and can never
        // be changed after the fact once set). A console seeder has no
        // authenticated user, so forceCreate() is the correct, explicit way
        // to set it here instead of relying on that Observer.
        return Project::forceCreate([
            'name' => $name,
            'ticket_prefix' => $ticketPrefix,
            'owner_id' => $owner->id,
            'status_id' => $status->id,
            'status_type' => 'default',
            'type' => $type,
            'organization_id' => $organization->id,
        ]);
    }

    private function addProjectMember(Project $project, User $user): void
    {
        // 'employee' here is a *project*-level role (config('system.projects
        // .affectations.roles')) - a completely different vocabulary from
        // the Organization-level 'owner'/'admin'/'member' roles above. Easy
        // to mix up; the two are unrelated pivots on purpose (see the class
        // docblock).
        $project->users()->syncWithoutDetaching([$user->id => ['role' => 'employee']]);
    }

    /**
     * @param  'Todo'|'In progress'|'Done'|'Archived'  $status  One of the
     *                                                          global TicketStatus names TicketStatusSeeder always creates (project_id
     *                                                          null, status_type='default' on every demo project above).
     */
    private function ticket(
        Project $project,
        string $name,
        string $status = 'Todo',
        ?Epic $epic = null,
        ?Sprint $sprint = null,
        ?User $responsible = null,
        ?float $estimation = null,
    ): Ticket {
        $existing = Ticket::where('project_id', $project->id)->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        $statusRow = TicketStatus::whereNull('project_id')->where('name', $status)->firstOrFail();
        $type = TicketType::where('organization_id', $project->organization_id)->where('is_default', true)->firstOrFail();
        $priority = TicketPriority::where('organization_id', $project->organization_id)->where('is_default', true)->firstOrFail();

        return Ticket::create([
            'name' => $name,
            'content' => 'Data contoh dari OrganizationDemoSeeder - aman dihapus/diedit.',
            'owner_id' => $project->owner_id,
            'responsible_id' => $responsible?->id,
            'status_id' => $statusRow->id,
            'project_id' => $project->id,
            'type_id' => $type->id,
            'priority_id' => $priority->id,
            'epic_id' => $epic?->id,
            'sprint_id' => $sprint?->id,
            'estimation' => $estimation,
        ]);
    }

    /**
     * Roadmap (app/Filament/Pages/RoadMap.php) draws its Gantt timeline from
     * Project::epicsFirstDate/epicsLastDate, both derived purely from
     * Epic::starts_at/ends_at - a project with zero Epics has nothing for
     * that page to show at all.
     */
    private function epic(Project $project, string $name, Carbon $startsAt, Carbon $endsAt): Epic
    {
        $existing = Epic::where('project_id', $project->id)->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        return Epic::create([
            'name' => $name,
            'project_id' => $project->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * Project::currentSprint (what the Kanban/Scrum board's ticket query
     * filters by) is the one Sprint with started_at set and ended_at still
     * null - so a demo project needs exactly that shape to show its Tickets
     * grouped into a live sprint instead of the empty-backlog fallback.
     *
     * SprintObserver::created() automatically mirrors every Sprint into its
     * own Epic (so it also shows on the Road Map) - that is why a project
     * with one epic() call and one sprint() call below ends up with two
     * Epic rows, not a bug in this seeder.
     */
    private function sprint(Project $project, string $name, Carbon $startedAt): Sprint
    {
        $existing = Sprint::where('project_id', $project->id)->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        return Sprint::create([
            'name' => $name,
            'project_id' => $project->id,
            'starts_at' => $startedAt->copy()->startOfDay(),
            'ends_at' => $startedAt->copy()->addWeek()->startOfDay(),
            'started_at' => $startedAt,
            'ended_at' => null,
        ]);
    }

    private function printCheatSheet(): void
    {
        $this->command->info(
            'Organization RBAC demo data ready. Every seeded user shares the password "'.self::PASSWORD.'" (development/local only).'
        );

        $this->command->line('');
        $this->command->table(
            ['Akun', 'Role per Organization', 'Project yang KELIHATAN', 'Bisa hapus Project?'],
            [
                [
                    'owner@example.test',
                    'Alpha: Owner · Delta: Owner',
                    'Semua project Alpha (Company Website, Internal HR Tool) - pindah ke Delta lewat switcher untuk lihat "Dashboard Analitik Internal" (organisasi baru, tim masih cuma dia sendiri)',
                    'Ya, semua project Alpha & Delta (Owner selalu bisa)',
                ],
                [
                    'admin@example.test',
                    'Alpha: Admin · Beta: Member',
                    'Semua project Alpha (lihat semua sbg Admin) — TAPI TIDAK ada project Beta (di Beta cuma "member" biasa)',
                    'Tidak — Admin cuma boleh lihat, bukan hapus (Fase 6b)',
                ],
                [
                    'member@example.test',
                    'Alpha: Member · Gamma: Member',
                    '"Company Website" (Alpha) dan "Aplikasi Manajemen Inventori" (Gamma) - ditambahkan manual jadi anggota kedua project itu — TIDAK lihat "Internal HR Tool" (Alpha, sengaja tidak ditambahkan)',
                    'Tidak',
                ],
                [
                    'multi@example.test',
                    'Beta: Owner · Gamma: Owner · Alpha: Member',
                    'Di Beta: "Mobile App Revamp" — Di Gamma: "Aplikasi Manajemen Inventori" (dia Owner keduanya, pindah lewat switcher) — Di Alpha: TIDAK ada, cuma "member" biasa di sana',
                    'Ya, project Beta & Gamma miliknya',
                ],
                [
                    'noorg@example.test',
                    '(belum join organisasi mana pun)',
                    'Tidak ada satupun',
                    '—',
                ],
            ]
        );
        $this->command->line('');
        $this->command->comment(
            'Baris "member@example.test" vs "Internal HR Tool" itu BUKAN bug - itu memang inti yang mau ditunjukkan seeder ini: '
            .'jadi anggota Organization tidak otomatis membuka semua Project di dalamnya, kecuali Owner/Admin. Bandingkan dengan '
            .'"Aplikasi Manajemen Inventori" di Gamma - member@example.test punya role Organization yang sama persis ("member") di '
            .'kedua organisasi, tapi DI SANA dia terlihat, karena memang ditambahkan manual sebagai anggota project itu.'
        );
        $this->command->line('');
        $this->command->comment(
            'Tiap project demo sudah ada Sprint aktif + Epic bertanggal + beberapa Task tersebar di Todo/In progress/Done, '
            .'jadi papan Kanban/Scrum dan halaman Road Map langsung ada isinya, tidak perlu dibuat manual dulu.'
        );
        $this->command->line('');
        $this->command->comment(
            'Akun yang jadi anggota lebih dari satu organisasi (owner@, admin@, multi@) otomatis login ke organisasi pertamanya - '
            .'pakai dropdown "Organization" di sidebar (App\\Http\\Livewire\\OrganizationSwitcher) untuk pindah ke organisasi lainnya.'
        );
    }
}
