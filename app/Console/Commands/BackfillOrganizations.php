<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 (Organization Foundation) backfill — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md.
 *
 * The round-10 audit found zero existing tenant/organization boundary in
 * this schema, so there is no business data to map multiple existing
 * projects to multiple organizations from — every project is assigned to
 * one "Default Organization", created (once) under this exact name so
 * re-running this command never creates a duplicate.
 *
 * Membership in that Default Organization is only granted to users who are
 * actually connected to a project today — a project owner (`owner_id`) or a
 * project_users member — never the whole `users` table, so an unrelated
 * Super Admin or a user with no project involvement is not silently
 * enrolled into an organization they have no business relationship with.
 *
 * Safe to run repeatedly: every read (projects requiring organization_id,
 * users requiring membership) is computed as a diff against current state,
 * so a second run only picks up drift (e.g. a project or membership added
 * since the last run) and changes nothing else.
 */
class BackfillOrganizations extends Command
{
    protected $signature = 'organizations:backfill
        {--dry-run : Report what would change without changing anything}';

    protected $description = 'Assign existing projects (and their owners/members) to a Default Organization';

    private const DEFAULT_ORGANIZATION_NAME = 'Default Organization';

    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Defensive integrity check: projects.owner_id is a NOT NULL FK
        // constrained to users, so this should always be zero. If it isn't,
        // something is wrong at the database level and guessing a mapping
        // would be unsafe — stop instead of backfilling blind.
        $orphaned = Project::withTrashed()
            ->whereDoesntHave('owner', fn ($q) => $q->withTrashed())
            ->count();

        if ($orphaned > 0) {
            $this->error("Ditemukan {$orphaned} project dengan owner yang tidak valid. Backfill dihentikan — butuh investigasi manual sebelum melanjutkan.");

            return self::FAILURE;
        }

        $totalProjects = Project::withTrashed()->count();
        $alreadyAssigned = Project::withTrashed()->whereNotNull('organization_id')->count();
        $requiringOrg = Project::withTrashed()->whereNull('organization_id')->count();

        $organization = Organization::where('name', self::DEFAULT_ORGANIZATION_NAME)->first();

        $targetUserIds = $this->targetUserIds();
        $existingMemberIds = $organization
            ? DB::table('organization_users')->where('organization_id', $organization->id)->pluck('user_id')
            : collect();
        $usersRequiringMembership = $targetUserIds->diff($existingMemberIds)->values();

        $willCreateOrganization = $organization === null && ($requiringOrg > 0 || $usersRequiringMembership->isNotEmpty());

        $this->printReport(
            totalProjects: $totalProjects,
            alreadyAssigned: $alreadyAssigned,
            requiringOrg: $requiringOrg,
            orphaned: $orphaned,
            usersRequiringMembership: $usersRequiringMembership,
            existingMemberships: $existingMemberIds->count(),
            willCreateOrganization: $willCreateOrganization,
        );

        if ($requiringOrg === 0 && $usersRequiringMembership->isEmpty()) {
            $this->line('');
            $this->info('Tidak ada yang perlu di-backfill.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('');
            $this->info('[dry-run] Tidak ada perubahan yang disimpan.');

            return self::SUCCESS;
        }

        try {
            $organization = $this->write($organization, $usersRequiringMembership);
        } catch (\Throwable $e) {
            $this->error('Backfill gagal dan di-rollback: '.$e->getMessage());

            return self::FAILURE;
        }

        $projectsUpdated = Project::withTrashed()
            ->where('organization_id', $organization->id)
            ->count() - $alreadyAssigned;

        $this->line('');
        $this->info("Backfill selesai. Organisasi: \"{$organization->name}\" (id {$organization->id}).");
        $this->info("Project di-assign: {$projectsUpdated}. Keanggotaan organisasi ditambahkan: {$usersRequiringMembership->count()}.");

        return self::SUCCESS;
    }

    /**
     * Distinct ids of every user connected to at least one project today,
     * as an owner or as a project_users member.
     */
    private function targetUserIds(): Collection
    {
        $ownerIds = Project::withTrashed()->whereNotNull('owner_id')->pluck('owner_id');
        $memberIds = DB::table('project_users')->pluck('user_id');

        return $ownerIds->merge($memberIds)->unique()->values();
    }

    private function printReport(
        int $totalProjects,
        int $alreadyAssigned,
        int $requiringOrg,
        int $orphaned,
        Collection $usersRequiringMembership,
        int $existingMemberships,
        bool $willCreateOrganization,
    ): void {
        $this->line('Existing projects: '.$totalProjects);
        $this->line('Projects already assigned: '.$alreadyAssigned);
        $this->line('Projects requiring organization: '.$requiringOrg);
        $this->line('Projects orphaned: '.$orphaned);
        $this->line('Users requiring organization membership: '.$usersRequiringMembership->count());
        $this->line('Existing memberships: '.$existingMemberships);
        $this->line('Organizations that will be created: '.($willCreateOrganization ? 1 : 0).' ("'.self::DEFAULT_ORGANIZATION_NAME.'")');
        $this->line('Records that will be inserted: '.$requiringOrg.' project(s), '.$usersRequiringMembership->count().' membership(s)');
        $this->line('Records that will be skipped: '.$alreadyAssigned.' project(s) already assigned, '.$existingMemberships.' membership(s) already existing');
        $this->line('Potential conflicts: none detected');
    }

    /**
     * Actual write path. Each unit (organization creation, one chunk of
     * project updates, one chunk of membership inserts) is its own
     * transaction — large enough to stay atomic per unit, small enough that
     * one giant transaction is never held open across the whole dataset.
     */
    private function write(?Organization $organization, Collection $usersRequiringMembership): Organization
    {
        if ($organization === null) {
            $organization = DB::transaction(
                fn () => Organization::firstOrCreate(['name' => self::DEFAULT_ORGANIZATION_NAME])
            );
        }

        Project::withTrashed()
            ->whereNull('organization_id')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $projects) use ($organization) {
                DB::transaction(function () use ($projects, $organization) {
                    Project::withTrashed()
                        ->whereIn('id', $projects->pluck('id'))
                        ->update(['organization_id' => $organization->id]);
                });
            });

        $usersRequiringMembership->chunk(self::CHUNK_SIZE)->each(function (Collection $chunk) use ($organization) {
            DB::transaction(function () use ($chunk, $organization) {
                $now = now();

                DB::table('organization_users')->insert(
                    $chunk->map(fn ($userId) => [
                        'organization_id' => $organization->id,
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
        });

        return $organization;
    }
}
