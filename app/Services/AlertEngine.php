<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertThreshold;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Checks symptom check-ins against the assigned clinician's thresholds and raises alerts.
 *
 * Two kinds of rule, both stored in the alert_thresholds table:
 *  - absolute:        window_days is null, threshold_value is the score limit (alert when score >= limit)
 *  - rate of change:  window_days is set,  threshold_value is the rise in points within that many days
 *
 * If a clinician has saved no thresholds at all, the system defaults are used (see AlertThreshold::defaultRules()).
 * The decision logic itself lives in AlertRules so it can be tested without a database.
 */
class AlertEngine
{
    /**
     * Evaluate one submission. Returns the alerts that were newly created.
     *
     * $onlyIfLatest skips check-ins that are older than the patient's newest one, so a phone that syncs a
     * batch of old offline check-ins does not raise alerts about the past. alerts:scan passes false
     * so that it can replay history in order.
     */
    public function evaluate(SymptomSubmission $submission, bool $onlyIfLatest = true): Collection
    {
        $created = collect();
        $patient = $submission->patient;

        if (! $patient) {
            return $created;
        }

        if ($onlyIfLatest && $this->hasNewerSubmission($submission)) {
            return $created;
        }

        $rulesByType = $this->rulesFor($patient)->groupBy('symptom_type');

        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $score = $submission->{$type};

            if ($score === null || ! $rulesByType->has($type)) {
                continue;
            }

            $reasons = [];

            foreach ($rulesByType[$type] as $rule) {
                $reason = $rule->isRateOfChange()
                    ? $this->riseReasonFor($submission, $type, (int) $score, $rule)
                    : $this->absoluteReasonFor((int) $score, $rule);

                if ($reason !== null) {
                    $reasons[] = $reason;
                }
            }

            if ($reasons === []) {
                continue;
            }

            $alert = $this->raise($patient, $submission, $type, (int) $score, implode('; ', $reasons));

            if ($alert) {
                $created->push($alert);
            }
        }

        return $created;
    }

    /** Evaluate a patient's newest check-in (kept for callers that work per patient). */
    public function checkPatient(Patient $patient): Collection
    {
        $latest = $patient->symptomSubmissions()->latest('submitted_at')->first();

        return $latest ? $this->evaluate($latest) : collect();
    }

    private function hasNewerSubmission(SymptomSubmission $submission): bool
    {
        return SymptomSubmission::where('patient_id', $submission->patient_id)
            ->where('submitted_at', '>', $submission->submitted_at)
            ->exists();
    }

    /** The active rules for the patient's assigned clinician, or the system defaults if none are saved. */
    private function rulesFor(Patient $patient): Collection
    {
        $rules = AlertThreshold::where('clinician_id', $patient->assigned_clinician_id)->get();

        if ($rules->isEmpty()) {
            return AlertThreshold::defaultRules($patient->assigned_clinician_id);
        }

        return $rules->where('is_active', true)->values();
    }

    private function absoluteReasonFor(int $score, AlertThreshold $rule): ?string
    {
        $limit = (int) $rule->threshold_value;

        return AlertRules::absoluteHit($score, $limit)
            ? AlertRules::absoluteReason($score, $limit)
            : null;
    }

    private function riseReasonFor(SymptomSubmission $submission, string $type, int $score, AlertThreshold $rule): ?string
    {
        $days = (int) $rule->window_days;
        $limit = (int) $rule->threshold_value;

        $earlier = SymptomSubmission::where('patient_id', $submission->patient_id)
            ->where('id', '!=', $submission->id)
            ->where('submitted_at', '>=', $submission->submitted_at->copy()->subDays($days))
            ->where('submitted_at', '<=', $submission->submitted_at)
            ->whereNotNull($type)
            ->pluck($type)
            ->all();

        $baseline = AlertRules::baseline($earlier);

        return AlertRules::riseHit($score, $baseline, $limit)
            ? AlertRules::riseReason($score, $baseline, $days, $limit)
            : null;
    }

    /** Create the alert, escalate the open one, or do nothing, depending on AlertRules::action(). */
    private function raise(Patient $patient, SymptomSubmission $submission, string $type, int $score, string $reason): ?Alert
    {
        $reason = Str::limit($reason, 250, '');

        $open = Alert::open()
            ->where('patient_id', $patient->id)
            ->where('symptom_type', $type)
            ->latest('triggered_at')
            ->first();

        $action = AlertRules::action($open?->severity, $score);

        if ($action === AlertRules::CREATE) {
            return Alert::create([
                'patient_id' => $patient->id,
                'triggered_at' => $submission->submitted_at,
                'symptom_type' => $type,
                'severity' => $score,
                'threshold_breached' => $reason,
            ]);
        }

        if ($action === AlertRules::ESCALATE) {
            // Keep one open alert per patient and symptom: raise its score instead of adding a duplicate.
            $open->update([
                'severity' => $score,
                'threshold_breached' => $reason,
            ]);
        }

        return null;
    }
}
