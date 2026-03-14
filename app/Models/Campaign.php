<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'company_id',
        'template_id',
        'name',
        'status',
        'scheduled_at',
        'total_deducted_cost',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'total_deducted_cost' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}