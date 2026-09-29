<?php

namespace App\Http\Resources\Comment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->description,
            'visibility' => $this->visibility,
            'user' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
