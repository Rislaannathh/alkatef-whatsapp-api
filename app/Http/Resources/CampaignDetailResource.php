<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Includes all campaign fields plus the contact_import status block
     * when the contactImportStatus relation has been eagerly loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $importStatus = $this->whenLoaded('contactImportStatus');

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
            'contact_import'   => $this->when(
                $importStatus instanceof \App\Models\ContactImportStatus,
                fn () => [
                    'status'      => $importStatus->status,
                    'total'       => $importStatus->total,
                    'inserted'    => $importStatus->inserted,
                    'failed_count'=> $importStatus->failed_count,
                    'message'     => $importStatus->message,
                ]
            ),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
