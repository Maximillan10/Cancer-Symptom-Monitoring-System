<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'assigned_clinician_id',
        'mobile_app_user_id',
        'name',
        'dob',
        'diagnosis',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
        ];
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_clinician_id');
    }

    public function symptomSubmissions(): HasMany
    {
        return $this->hasMany(SymptomSubmission::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function unresolvedAlerts(): HasMany
    {
        return $this->alerts()->whereNull('resolved_at');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** Most recent symptom submission, useful for dashboard patient list view */
    public function latestSubmission()
    {
        return $this->hasOne(SymptomSubmission::class)->latestOfMany('submitted_at');
    }
}
