<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappConfig extends Model
{
    protected $fillable = [
        'company_id',
        'waba_id',
        'phone_number_id',
        'phone_number',
        'access_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}