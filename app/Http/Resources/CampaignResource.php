<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'description'      => $this->description,
            'status'           => $this->status,
            'scheduled_at'     => $this->scheduled_at?->toIso8601String(),
            'started_at'       => $this->started_at?->toIso8601String(),
            'completed_at'     => $this->completed_at?->toIso8601String(),
            'total_recipients' => $this->total_recipients,
            'sent_count'       => $this->sent_count,
            'delivered_count'  => $this->delivered_count,
            'read_count'       => $this->read_count,
            'failed_count'     => $this->failed_count,
            'template'         => $this->whenLoaded('template', fn ($tmpl) => new TemplateResource($tmpl)),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
