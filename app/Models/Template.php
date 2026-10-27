<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'language',
        'category',
        'media',
        'media_type',
        'header',
        'body',
        'footer',
        'button',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'button' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The category this template belongs to.
     *
     * Note: the FK column is named 'category' (not 'category_id') because the
     * migration used `foreignId('category')`.  We pass the column name explicitly
     * so Eloquent resolves the relation correctly.
     *
     * The method is intentionally named 'templateCategory' (not 'category') to
     * avoid a name collision with the 'category' column in $fillable — if the
     * method shares its name with a plain column Eloquent's attribute accessor
     * wins, making eager-loading impossible.
     */
    public function templateCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category');
    }
}
