<?php

namespace App\Models;

use App\Services\AlertRules;
use Illuminate\Database\Eloquent\Builder;
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

    /** Alerts nobody has resolved yet. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    /** A clinician sees alerts for their own patients; an admin sees all of them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('patient', fn (Builder $patients) => $patients->where('assigned_clinician_id', $user->id));
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

    /** critical, warning or informational (see AlertRules::priority). */
    public function priority(): string
    {
        return AlertRules::priority((int) $this->severity);
    }

    public function priorityLabel(): string
    {
        return ucfirst($this->priority());
    }

    /** Bootstrap badge classes for the priority. */
    public function priorityBadge(): string
    {
        return match ($this->priority()) {
            'critical' => 'text-bg-danger',
            'warning' => 'text-bg-warning',
            default => 'text-bg-info',
        };
    }
}
