<?php

namespace Tests\Support;

use App\Models\Alert;
use App\Models\AlertThreshold;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Helpers for building clinical test data without relying on model factories.
 * Check-ins go through SymptomSubmission::create(), so the real observer + engine run,
 * exactly as they do for the mobile sync API.
 */
trait ClinicalFixtures
{
    protected function makeClinician(array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create($attrs + [
            'name' => "Test Clinician $n",
            'email' => "clinician{$n}_" . uniqid() . '@test.local',
            'password' => 'password123',
            'role' => 'clinician',
        ]);
    }

    protected function makeAdmin(): User
    {
        return $this->makeClinician(['role' => 'admin', 'name' => 'Test Admin']);
    }

    protected function makePatient(?User $clinician = null, array $attrs = []): Patient
    {
        static $n = 0;
        $n++;
        $clinician ??= $this->makeClinician();

        return Patient::create($attrs + [
            'assigned_clinician_id' => $clinician->id,
            'mobile_app_user_id' => 'mob-test-' . $n . '-' . uniqid(),
            'name' => "Test Patient $n",
            'dob' => '1970-01-01',
            'diagnosis' => 'Simulated test patient',
        ]);
    }

    /** Store one check-in. Unlisted symptoms default to 1 so they never trip a rule. */
    protected function checkIn(Patient $patient, array $scores, $at, bool $withoutEvents = false): SymptomSubmission
    {
        $row = array_merge(
            array_fill_keys(SymptomSubmission::SYMPTOM_TYPES, AlertScenarios::BASELINE),
            $scores,
            ['patient_id' => $patient->id, 'submitted_at' => $at instanceof Carbon ? $at : Carbon::parse($at)]
        );

        return $withoutEvents
            ? SymptomSubmission::withoutEvents(fn () => SymptomSubmission::create($row))
            : SymptomSubmission::create($row);
    }

    /**
     * Save a clinician's full rule set: one absolute + one rate-of-change rule per symptom
     * (system defaults), with overrides.  $set = ['pain' => ['abs' => 5, 'rise' => [3, 3]]]
     * $off = ['pain' => ['abs', 'rise']] deactivates those rules.
     */
    protected function saveRules(User $clinician, array $set = [], array $off = []): void
    {
        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $abs = $set[$type]['abs'] ?? AlertThreshold::DEFAULT_ABSOLUTE;
            [$rise, $days] = $set[$type]['rise'] ?? [AlertThreshold::DEFAULT_RISE, AlertThreshold::DEFAULT_WINDOW];

            AlertThreshold::create([
                'clinician_id' => $clinician->id, 'symptom_type' => $type,
                'threshold_value' => $abs, 'window_days' => null,
                'is_active' => ! in_array('abs', $off[$type] ?? [], true),
            ]);
            AlertThreshold::create([
                'clinician_id' => $clinician->id, 'symptom_type' => $type,
                'threshold_value' => $rise, 'window_days' => $days,
                'is_active' => ! in_array('rise', $off[$type] ?? [], true),
            ]);
        }
    }

    /** Resolve every open alert of a patient (what a clinician clicking "Resolve" does). */
    protected function resolveAll(Patient $patient, User $by): void
    {
        Alert::open()->where('patient_id', $patient->id)->get()->each->resolve($by);
    }
}
