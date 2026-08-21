<?php

namespace App\Observers;

use App\Models\Epic;
use App\Models\Sprint;

/**
 * Every sprint is mirrored by an epic; create it and link it back on creation,
 * and keep it in step afterwards.
 */
class SprintObserver
{
    /** The sprint fields the mirrored epic copies. */
    private const MIRRORED = ['name', 'starts_at', 'ends_at'];

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

    /**
     * A mirror that only ever copied the sprint's opening state is not a
     * mirror: the road map draws the epic, not the sprint, so renaming or
     * rescheduling a sprint used to leave the Gantt showing the name and the
     * bar it had on the day it was created.
     *
     * Only the mirrored fields trigger the copy, which also keeps the epic_id
     * write in created() above from bouncing back here.
     */
    public function updated(Sprint $sprint): void
    {
        if (! $sprint->wasChanged(self::MIRRORED)) {
            return;
        }

        $sprint->epic?->update([
            'name' => $sprint->name,
            'starts_at' => $sprint->starts_at,
            'ends_at' => $sprint->ends_at,
        ]);
    }
}
