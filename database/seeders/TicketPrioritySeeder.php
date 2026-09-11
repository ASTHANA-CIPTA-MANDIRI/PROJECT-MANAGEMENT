<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TicketPriority;
use Illuminate\Database\Seeder;

class TicketPrioritySeeder extends Seeder
{
    /**
     * @return array<int, array{name:string, color:string, is_default:bool}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Low',
                'color' => '#008000',
                'is_default' => false,
            ],
            [
                'name' => 'Normal',
                'color' => '#CECECE',
                'is_default' => true,
            ],
            [
                'name' => 'High',
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
            TicketPriority::firstOrCreate(['name' => $item['name'], 'organization_id' => null], $item);
        }
    }

    /**
     * Fase 3B — a fresh Organization's own starter set.
     */
    public static function seedFor(Organization $organization): void
    {
        foreach (self::defaults() as $item) {
            TicketPriority::create([...$item, 'organization_id' => $organization->id]);
        }
    }
}
