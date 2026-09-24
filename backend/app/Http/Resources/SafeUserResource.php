<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SafeUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->roleRelation ? [
                'id' => $this->roleRelation->id,
                'name' => $this->roleRelation->name,
            ] : null,
            'requires_password_change' => (bool) $this->force_change_password,
            'created_at' => $this->created_at,
        ];
    }
}
