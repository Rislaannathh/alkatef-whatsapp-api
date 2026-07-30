<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'country_code',
        'phone',
        'email',
        'company',
        'status',
        'notes',
        'created_by',
    ];
}
