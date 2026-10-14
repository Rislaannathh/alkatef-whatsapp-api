<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'company_id',
        'name',
        'country_code',
        'phone',
        'email',
        'data',
        'status',
        'created_by',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
