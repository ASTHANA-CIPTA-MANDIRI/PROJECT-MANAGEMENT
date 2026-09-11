<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\ProjectStatus;
use Illuminate\Database\Seeder;

class ProjectStatusSeeder extends Seeder
{
    /**
     * @return array<int, array{name:string, color:string, is_default:bool}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Not started', 'color' => '#cecece', 'is_default' => true],
            ['name' => 'In progress', 'color' => '#ff7f00', 'is_default' => false],
            ['name' => 'On hold', 'color' => '#eab308', 'is_default' => false],
            ['name' => 'Completed', 'color' => '#008000', 'is_default' => false],
            ['name' => 'Cancelled', 'color' => '#ff0000', 'is_default' => false],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $item) {
            ProjectStatus::firstOrCreate(['name' => $item['name'], 'organization_id' => null], $item);
        }
    }

    /**
     * Fase 3B — a fresh Organization's own starter set.
     */
    public static function seedFor(Organization $organization): void
    {
        foreach (self::defaults() as $item) {
            ProjectStatus::create([...$item, 'organization_id' => $organization->id]);
        }
    }
}
