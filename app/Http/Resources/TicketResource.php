<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Ticket
 */
class TicketResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'content' => $this->content,
            'order' => $this->order,
            'estimation' => $this->estimation,
            'project_id' => $this->project_id,
            'owner_id' => $this->owner_id,
            'responsible_id' => $this->responsible_id,
            'status_id' => $this->status_id,
            'type_id' => $this->type_id,
            'priority_id' => $this->priority_id,
            'epic_id' => $this->epic_id,
            'sprint_id' => $this->sprint_id,
            'owner' => new UserResource($this->whenLoaded('owner')),
            'responsible' => new UserResource($this->whenLoaded('responsible')),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'name' => $this->status->name,
                'color' => $this->status->color,
            ]),
            'comments_count' => $this->whenCounted('comments'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
