<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImportStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'status',
        'total',
        'inserted',
        'failed_count',
        'last_processed_row',
        'message',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
