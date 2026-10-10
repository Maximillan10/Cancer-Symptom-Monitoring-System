<?php

namespace Tests\Concerns;

use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Models\User;
use Carbon\CarbonInterface;

/** Small helpers that build clinicians, patients and check-ins for the feature tests. */
trait CreatesClinicalData
{
    private int $seq = 0;

    protected function clinician(array $attributes = []): User
    {
        return $this->makeUser('clinician', $attributes);
    }

    protected function admin(array $attributes = []): User
    {
        return $this->makeUser('admin', $attributes);
    }

    private function makeUser(string $role, array $attributes): User
    {
        $this->seq++;

        return User::create(array_merge([
            'name' => ucfirst($role)." {$this->seq}",
            'email' => "{$role}{$this->seq}@example.test",
            'password' => 'password123',
            'role' => $role,
        ], $attributes));
    }

    protected function patientFor(User $clinician, array $attributes = []): Patient
    {
        $this->seq++;

        return Patient::create(array_merge([
            'assigned_clinician_id' => $clinician->id,
            'name' => "Patient {$this->seq}",
            'dob' => '1970-01-01',
            'diagnosis' => 'Simulated test patient',
        ], $attributes));
    }

    /** Stores a check-in the way the API does, so the alert observer runs. */
    protected function checkIn(Patient $patient, array $scores, ?CarbonInterface $at = null): SymptomSubmission
    {
        return SymptomSubmission::create(array_merge($scores, [
            'patient_id' => $patient->id,
            'submitted_at' => $at ?? now(),
        ]));
    }
}
