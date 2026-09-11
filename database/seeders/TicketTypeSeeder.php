<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TicketType;
use Illuminate\Database\Seeder;

class TicketTypeSeeder extends Seeder
{
    /**
     * @return array<int, array{name:string, icon:string, color:string, is_default:bool}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Task',
                'icon' => 'heroicon-o-check-circle',
                'color' => '#00FFFF',
                'is_default' => true,
            ],
            [
                'name' => 'Evolution',
                'icon' => 'heroicon-o-clipboard-list',
                'color' => '#008000',
                'is_default' => false,
            ],
            [
                'name' => 'Bug',
                'icon' => 'heroicon-o-x',
                'color' => '#ff0000',
                'is_default' => false,
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
            TicketType::firstOrCreate(['name' => $item['name'], 'organization_id' => null], $item);
        }
    }

    /**
     * Fase 3B — a fresh Organization's own starter set. See
     * ActivitySeeder::seedFor() for why this is plain create() rather than
     * firstOrCreate().
     */
    public static function seedFor(Organization $organization): void
    {
        foreach (self::defaults() as $item) {
            TicketType::create([...$item, 'organization_id' => $organization->id]);
        }
    }
}
