<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class SymptomSubmissionApiTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    public function test_submissions_require_authentication(): void
    {
        $this->postJson('/api/symptom-submissions', ['patient_id' => 1, 'pain' => 3])->assertUnauthorized();
        $this->getJson('/api/patients/1/symptom-submissions')->assertUnauthorized();
    }

    public function test_a_check_in_can_be_stored_by_patient_id(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 3, 'fatigue' => 4])
            ->assertCreated()->assertJsonPath('pain', 3)->assertJsonPath('fatigue', 4);

        $this->assertDatabaseCount('symptom_submissions', 1);
    }

    public function test_a_check_in_can_be_stored_by_mobile_app_user_id(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me, ['mobile_app_user_id' => 'phone-77']);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['mobile_app_user_id' => 'phone-77', 'nausea' => 2])
            ->assertCreated();

        $this->assertDatabaseHas('symptom_submissions', ['patient_id' => $patient->id, 'nausea' => 2]);
    }

    public function test_a_score_of_zero_is_accepted(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 0])->assertCreated();
        $this->assertDatabaseHas('symptom_submissions', ['patient_id' => $patient->id, 'pain' => 0]);
    }

    public function test_a_check_in_with_no_score_is_rejected(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'notes' => 'felt fine'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('symptom_submissions', 0);
    }

    public static function invalidScores(): array
    {
        return [
            'above the scale' => [['pain' => 11]],
            'below the scale' => [['pain' => -1]],
            'not a number' => [['pain' => 'severe']],
            'a decimal' => [['fatigue' => 4.5]],
        ];
    }

    #[DataProvider('invalidScores')]
    public function test_out_of_range_scores_are_rejected(array $scores): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id] + $scores)->assertUnprocessable();
        $this->assertDatabaseCount('symptom_submissions', 0);
    }

    public function test_a_check_in_dated_in_the_future_is_rejected(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', [
            'patient_id' => $patient->id, 'pain' => 3, 'submitted_at' => now()->addDays(2)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('submitted_at');
    }

    public function test_an_unknown_patient_is_rejected(): void
    {
        Sanctum::actingAs($this->clinician());

        $this->postJson('/api/symptom-submissions', ['patient_id' => 999999, 'pain' => 3])->assertUnprocessable();
        $this->postJson('/api/symptom-submissions', ['mobile_app_user_id' => 'nobody', 'pain' => 3])->assertUnprocessable();
    }

    public function test_a_clinician_cannot_submit_for_another_clinicians_patient(): void
    {
        $patient = $this->patientFor($this->clinician());
        Sanctum::actingAs($this->clinician());

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 3])->assertForbidden();
        $this->assertDatabaseCount('symptom_submissions', 0);
    }

    public function test_history_is_newest_first(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->checkIn($patient, ['pain' => 1], now()->subDays(3));
        $this->checkIn($patient, ['pain' => 2], now()->subDays(1));
        $this->checkIn($patient, ['pain' => 3], now()->subDays(2));
        Sanctum::actingAs($me);

        $pains = collect($this->getJson("/api/patients/{$patient->id}/symptom-submissions")->assertOk()->json('data'))->pluck('pain')->all();

        $this->assertSame([2, 3, 1], $pains);
    }

    public function test_history_can_be_filtered_by_date(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->checkIn($patient, ['pain' => 1], now()->subDays(10));
        $this->checkIn($patient, ['pain' => 2], now()->subDays(2));
        Sanctum::actingAs($me);

        $from = now()->subDays(5)->toDateString();
        $this->getJson("/api/patients/{$patient->id}/symptom-submissions?from={$from}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_history_rejects_a_bad_date_range(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->getJson("/api/patients/{$patient->id}/symptom-submissions?from=2026-02-10&to=2026-02-01")
            ->assertUnprocessable();
    }

    public function test_history_of_another_clinicians_patient_is_forbidden(): void
    {
        $patient = $this->patientFor($this->clinician());
        Sanctum::actingAs($this->clinician());

        $this->getJson("/api/patients/{$patient->id}/symptom-submissions")->assertForbidden();
    }

    // Integration: the sync endpoint feeds the alert engine through the model observer.

    public function test_a_severe_check_in_through_the_api_raises_an_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 9])->assertCreated();

        $this->assertDatabaseHas('alerts', ['patient_id' => $patient->id, 'symptom_type' => 'pain', 'severity' => 9]);
    }

    public function test_a_mild_check_in_through_the_api_raises_no_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 3, 'fatigue' => 4])->assertCreated();

        $this->assertSame(0, Alert::count());
    }

    public function test_repeated_severe_check_ins_keep_one_open_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        foreach ([7, 8, 8, 9] as $i => $pain) {
            $this->postJson('/api/symptom-submissions', [
                'patient_id' => $patient->id, 'pain' => $pain, 'submitted_at' => now()->subDays(5 - $i)->toIso8601String(),
            ])->assertCreated();
        }

        $this->assertSame(1, Alert::where('patient_id', $patient->id)->count());
        $this->assertSame(9, Alert::where('patient_id', $patient->id)->value('severity'));
    }

    public function test_a_failure_in_the_alert_engine_does_not_fail_the_sync_request(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->mock(\App\Services\AlertEngine::class)
            ->shouldReceive('evaluate')->andThrow(new \RuntimeException('engine down'));

        $this->postJson('/api/symptom-submissions', ['patient_id' => $patient->id, 'pain' => 9])->assertCreated();
        $this->assertSame(1, SymptomSubmission::count());
    }
}
