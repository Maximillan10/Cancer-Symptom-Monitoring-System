<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertThreshold extends Model
{
    use HasFactory;

    protected $fillable = [
        'clinician_id',
        'symptom_type',
        'threshold_value',
        'window_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'threshold_value' => 'integer',
            'window_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_id');
    }

    public function isRateOfChange(): bool
    {
        return $this->window_days !== null;
    }
}
