<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AlertThreshold extends Model
{
    use HasFactory;

    /** System defaults, used until a clinician saves their own thresholds. */
    public const DEFAULT_ABSOLUTE = 7;   // alert when a score reaches 7
    public const DEFAULT_RISE = 3;       // alert when a score rises by 3 ...
    public const DEFAULT_WINDOW = 3;     // ... within 3 days

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

    /** window_days set = rate-of-change rule; null = absolute rule. */
    public function isRateOfChange(): bool
    {
        return $this->window_days !== null;
    }

    /**
     * The default rules as unsaved models: one absolute and one rate-of-change rule per symptom.
     */
    public static function defaultRules(?int $clinicianId = null): Collection
    {
        $rules = collect();

        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $rules->push(new static([
                'clinician_id' => $clinicianId,
                'symptom_type' => $type,
                'threshold_value' => self::DEFAULT_ABSOLUTE,
                'window_days' => null,
                'is_active' => true,
            ]));

            $rules->push(new static([
                'clinician_id' => $clinicianId,
                'symptom_type' => $type,
                'threshold_value' => self::DEFAULT_RISE,
                'window_days' => self::DEFAULT_WINDOW,
                'is_active' => true,
            ]));
        }

        return $rules;
    }
}
