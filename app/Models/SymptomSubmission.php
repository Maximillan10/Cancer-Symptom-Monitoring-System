<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymptomSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'submitted_at',
        'pain',
        'fatigue',
        'nausea',
        'anxiety',
        'appetite',
        'dyspnea',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'pain' => 'integer',
            'fatigue' => 'integer',
            'nausea' => 'integer',
            'anxiety' => 'integer',
            'appetite' => 'integer',
            'dyspnea' => 'integer',
        ];
    }

    /** The six symptom columns this system tracks — used by the alert engine to iterate. */
    public const SYMPTOM_TYPES = ['pain', 'fatigue', 'nausea', 'anxiety', 'appetite', 'dyspnea'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Convenience: symptom_type => severity pairs, skipping nulls. Used by SymptomSubmissionObserver. */
    public function severities(): array
    {
        return collect(self::SYMPTOM_TYPES)
            ->mapWithKeys(fn ($type) => [$type => $this->{$type}])
            ->filter(fn ($value) => $value !== null)
            ->toArray();
    }
}
