<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'triggered_at',
        'symptom_type',
        'severity',
        'threshold_breached',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'severity' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function resolve(User $clinician): void
    {
        $this->update([
            'resolved_at' => now(),
            'resolved_by' => $clinician->id,
        ]);
    }
}
