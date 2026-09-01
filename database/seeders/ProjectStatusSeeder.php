<?php

namespace Database\Seeders;

use App\Models\ProjectStatus;
use Illuminate\Database\Seeder;

class ProjectStatusSeeder extends Seeder
{
    private array $data = [
        ['name' => 'Not started', 'color' => '#cecece', 'is_default' => true],
        ['name' => 'In progress', 'color' => '#ff7f00', 'is_default' => false],
        ['name' => 'On hold', 'color' => '#eab308', 'is_default' => false],
        ['name' => 'Completed', 'color' => '#008000', 'is_default' => false],
        ['name' => 'Cancelled', 'color' => '#ff0000', 'is_default' => false],
    ];

    public function run(): void
    {
        foreach ($this->data as $item) {
            ProjectStatus::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
