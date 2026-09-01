<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A personal access token as the owner sees it afterwards.
 *
 * The fields are listed one by one on purpose: the `token` column holds the
 * SHA-256 of the secret and must never travel back out, not even hashed. The
 * plain text secret exists only in the response to the request that created
 * the token, where the controller adds it alongside this resource.
 *
 * @mixin \Laravel\Sanctum\PersonalAccessToken
 */
class PersonalAccessTokenResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities,
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
