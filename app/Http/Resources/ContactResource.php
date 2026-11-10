<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->first_name . ' ' . $this->last_name,

            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,

            'category' => $this->category,
            'profile_image_url' => $this->profile_image_url,
            'is_favorite' => $this->is_favorite,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
