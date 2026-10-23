<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'language'           => $this->language,
            'category'           => $this->whenLoaded('templateCategory', fn ($cat) => new CategoryResource($cat)),
            'media'              => $this->media,
            'media_type'         => $this->media_type,
            'header'             => $this->header,
            'body'               => $this->body,
            'footer'             => $this->footer,
            'button'                    => $this->button,
            'button_action_type'        => $this->button_action_type,
            'button_value'              => $this->button_value,
            'button_two'                => $this->button_two,
            'button_two_action_type'    => $this->button_two_action_type,
            'button_two_value'          => $this->button_two_value,
            'status'             => $this->status,
            'created_at'         => $this->created_at?->toIso8601String(),
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}
