<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertThreshold;
use App\Models\Patient;
use App\Models\SymptomSubmission;

class AlertEngine
{
    public function checkPatient(Patient $patient): void
    {
        $thresholds = AlertThreshold::where('clinician_id', $patient->assigned_clinician_id)
            ->where('is_active', true)
            ->get();

        $recent = SymptomSubmission::where('patient_id', $patient->id)
            ->where('submitted_at', '>=', now()->subDays(7))
            ->get();

        foreach ($thresholds as $threshold) {
            $avg = $recent->avg($threshold->symptom_type);

            if ($avg >= $threshold->threshold_value) {
                $existing = Alert::where('patient_id', $patient->id)
                    ->where('symptom_type', $threshold->symptom_type)
                    ->whereNull('resolved_at')
                    ->exists();

                if (!$existing) {
                    Alert::create([
                        'patient_id'        => $patient->id,
                        'triggered_at'      => now(),
                        'symptom_type'      => $threshold->symptom_type,
                        'severity'          => $avg >= 8 ? 'critical' : 'warning',
                        'threshold_breached' => $threshold->threshold_value,
                    ]);
                }
            }
        }
    }
}