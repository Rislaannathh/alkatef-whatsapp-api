<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\CategoryResource;

class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "name" => $this->name,
            "category" => new CategoryResource($this->whenLoaded('category')),
            "country_code" => $this->country_code,
            "phone" => $this->phone,
            "email" => $this->email,
            "company" => $this->company,
            "status" => $this->status,
            "notes" => $this->notes,
        ];
    }
}
