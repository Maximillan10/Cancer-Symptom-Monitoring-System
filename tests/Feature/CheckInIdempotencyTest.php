<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

/** Finding f07: a phone that retries a sync must not create the same check-in twice. */
class CheckInIdempotencyTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    private const WHEN = '2026-10-01T09:00:00+00:00';

    public function test_resending_the_same_check_in_stores_it_once(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);
        $payload = ['patient_id' => $patient->id, 'pain' => 4, 'fatigue' => 3, 'submitted_at' => self::WHEN];

        $this->postJson('/api/symptom-submissions', $payload)->assertCreated();
        $second = $this->postJson('/api/symptom-submissions', $payload)->assertOk();

        $this->assertSame(1, SymptomSubmission::count());
        $this->assertSame(4, $second->json('pain'));
    }

    public function test_resending_a_severe_check_in_does_not_duplicate_the_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);
        $payload = ['patient_id' => $patient->id, 'pain' => 9, 'submitted_at' => self::WHEN];

        $this->postJson('/api/symptom-submissions', $payload)->assertCreated();
        $this->postJson('/api/symptom-submissions', $payload)->assertOk();

        $this->assertSame(1, Alert::where('patient_id', $patient->id)->count());
    }

    public function test_the_same_time_with_different_scores_is_a_conflict_and_changes_nothing(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 4, 'submitted_at' => self::WHEN])->assertCreated();
        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 8, 'submitted_at' => self::WHEN])
            ->assertStatus(409);

        $this->assertSame(1, SymptomSubmission::count());
        $this->assertSame(4, SymptomSubmission::first()->pain);
    }

    public function test_the_same_time_for_two_different_patients_is_two_check_ins(): void
    {
        $me = $this->clinician();
        $a = $this->patientFor($me);
        $b = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $a->id, 'pain' => 4, 'submitted_at' => self::WHEN])->assertCreated();
        $this->postJson('/api/symptom-submissions', ['patient_id' => $b->id, 'pain' => 4, 'submitted_at' => self::WHEN])->assertCreated();

        $this->assertSame(2, SymptomSubmission::count());
    }

    public function test_different_times_are_separate_check_ins(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 4, 'submitted_at' => self::WHEN])->assertCreated();
        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 4, 'submitted_at' => '2026-10-02T09:00:00+00:00'])->assertCreated();

        $this->assertSame(2, SymptomSubmission::count());
    }

    public function test_check_ins_without_a_time_are_not_treated_as_repeats(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 4])->assertCreated();
        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 4])->assertCreated();

        $this->assertSame(2, SymptomSubmission::count());
    }

    public function test_a_repeat_for_another_clinicians_patient_is_still_forbidden(): void
    {
        $owner = $this->clinician();
        $patient = $this->patientFor($owner);
        $this->checkIn($patient, ['pain' => 4], \Carbon\Carbon::parse(self::WHEN));
        Sanctum::actingAs($this->clinician());

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 4, 'submitted_at' => self::WHEN])
            ->assertForbidden();
    }
}
