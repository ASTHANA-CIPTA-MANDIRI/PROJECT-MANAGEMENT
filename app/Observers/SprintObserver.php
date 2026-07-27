<?php

namespace App\Observers;

use App\Models\Epic;
use App\Models\Sprint;

/**
 * Every sprint is mirrored by an epic; create it and link it back on creation.
 */
class SprintObserver
{
    public function created(Sprint $sprint): void
    {
        $epic = Epic::create([
            'name' => $sprint->name,
            'starts_at' => $sprint->starts_at,
            'ends_at' => $sprint->ends_at,
            'project_id' => $sprint->project_id,
        ]);
        $sprint->epic_id = $epic->id;
        $sprint->save();
    }
}
