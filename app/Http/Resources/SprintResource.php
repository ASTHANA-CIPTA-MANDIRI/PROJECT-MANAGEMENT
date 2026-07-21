<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Sprint
 */
class SprintResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'project_id' => $this->project_id,
            'epic_id' => $this->epic_id,
            'starts_at' => optional($this->starts_at)->toDateString(),
            'ends_at' => optional($this->ends_at)->toDateString(),
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'is_active' => (bool) ($this->started_at && ! $this->ended_at),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
