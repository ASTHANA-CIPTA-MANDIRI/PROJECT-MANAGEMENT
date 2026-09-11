<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ActivitySeeder extends Seeder
{
    /**
     * The starter set every installation (via run(), organization_id null)
     * and every newly provisioned Organization (via seedFor(), Fase 3B)
     * both get. A single source for this data so the two paths can never
     * drift apart.
     *
     * @return array<int, array{name:string, description:string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Programming',
                'description' => 'Programming related activities',
            ],
            [
                'name' => 'Testing',
                'description' => 'Testing related activities',
            ],
            [
                'name' => 'Learning',
                'description' => 'Activities related to learning and training',
            ],
            [
                'name' => 'Research',
                'description' => 'Activities related to research',
            ],
            [
                'name' => 'Other',
                'description' => 'Other activities',
            ],
        ];
    }

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::defaults() as $item) {
            Activity::firstOrCreate(['name' => $item['name'], 'organization_id' => null], $item);
        }
    }

    /**
     * Fase 3B — a fresh Organization's own starter set, called from
     * App\Listeners\Concerns\ProvisionsPersonalOrganization (self-serve
     * signup) and App\Filament\Pages\CreateOrganization (the manual path,
     * still reachable for admin-created accounts that never went through
     * auto-provisioning). Plain create(), not firstOrCreate(): a brand new
     * Organization has no Activity rows of its own yet, so there is
     * nothing to collide with — unlike run()'s organization_id-null rows,
     * which do need the idempotent guard for repeat `db:seed` runs.
     */
    public static function seedFor(Organization $organization): void
    {
        foreach (self::defaults() as $item) {
            Activity::create([...$item, 'organization_id' => $organization->id]);
        }
    }
}
