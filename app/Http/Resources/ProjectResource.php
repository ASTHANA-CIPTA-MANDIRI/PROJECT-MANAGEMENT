<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'ticket_prefix' => $this->ticket_prefix,
            'type' => $this->type,
            'status_type' => $this->status_type,
            'owner_id' => $this->owner_id,
            'status_id' => $this->status_id,
            'owner' => new UserResource($this->whenLoaded('owner')),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'name' => $this->status->name,
                'color' => $this->status->color,
            ]),
            'members' => UserResource::collection($this->whenLoaded('users')),
            'tickets_count' => $this->whenCounted('tickets'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
