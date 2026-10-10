<?php

namespace Tests\Feature\Api;

use App\Models\Alert;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

/** POST /api/symptom-submissions (mobile sync) and GET /api/patients/{patient}/symptom-submissions */
class SymptomSubmissionEndpointsTest extends TestCase
{
    use RefreshDatabase, ClinicalFixtures;

    private function scores(array $over = []): array
    {
        return $over + ['pain' => 3, 'fatigue' => 4, 'nausea' => 2, 'anxiety' => 3, 'appetite' => 2, 'dyspnea' => 1];
    }

    // ---- store: identifying the patient ---------------------------------------------------

    public function test_store_by_patient_id(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id, 'notes' => 'Slept badly']))
            ->assertCreated()->assertJsonPath('pain', 3)->assertJsonPath('notes', 'Slept badly')->assertJsonPath('patient_id', $p->id);
        $this->assertSame(1, $p->symptomSubmissions()->count());
    }

    public function test_store_by_mobile_app_user_id(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me, ['mobile_app_user_id' => 'mob-xyz']);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', $this->scores(['mobile_app_user_id' => 'mob-xyz']))
            ->assertCreated()->assertJsonPath('patient_id', $p->id);
    }

    public function test_store_needs_a_patient_identifier_that_exists(): void
    {
        Sanctum::actingAs($this->makeClinician());

        $this->postJson('/api/symptom-submissions', $this->scores())->assertUnprocessable()->assertJsonValidationErrors(['patient_id', 'mobile_app_user_id']);
        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->postJson('/api/symptom-submissions', $this->scores(['mobile_app_user_id' => 'ghost']))->assertUnprocessable()->assertJsonValidationErrors('mobile_app_user_id');
        $this->assertSame(0, SymptomSubmission::count());
    }

    // ---- store: validation ------------------------------------------------------------------

    public function test_scores_must_be_integers_from_0_to_10(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        foreach ([11, -1, 'abc', 5.5] as $bad) {
            $this->postJson('/api/symptom-submissions', ['patient_id' => $p->id, 'pain' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('pain');
        }
        foreach ([0, 10] as $edge) {
            $this->postJson('/api/symptom-submissions', ['patient_id' => $p->id, 'pain' => $edge, 'submitted_at' => now()->subMinutes($edge + 1)->toIso8601String()])->assertCreated();
        }
    }

    public function test_at_least_one_score_is_required(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $p->id, 'notes' => 'only a note'])
            ->assertUnprocessable()->assertJsonPath('message', 'At least one symptom score (0-10) is required.')->assertJsonValidationErrors('symptoms');
        $this->assertSame(0, SymptomSubmission::count());
    }

    public function test_partial_scores_are_accepted_and_the_rest_stay_null(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $p->id, 'pain' => 4])
            ->assertCreated()->assertJsonPath('pain', 4)->assertJsonPath('fatigue', null);
    }

    public function test_future_timestamps_are_refused_but_small_clock_drift_is_allowed(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id, 'submitted_at' => now()->addHour()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors('submitted_at');
        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id, 'submitted_at' => now()->addMinutes(5)->toIso8601String()]))
            ->assertCreated();
    }

    public function test_submitted_at_defaults_to_now_and_back_dated_values_are_kept(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $old = Carbon::parse('2026-02-01 07:30:00');
        $r = $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id, 'submitted_at' => $old->toIso8601String()]))->assertCreated();
        $this->assertTrue(Carbon::parse($r->json('submitted_at'))->equalTo($old));

        $r = $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id]))->assertCreated();
        $this->assertTrue(Carbon::parse($r->json('submitted_at'))->diffInSeconds(now(), true) < 10);
    }

    // ---- store: access control ---------------------------------------------------------------

    public function test_cannot_submit_for_another_clinicians_patient(): void
    {
        $theirs = $this->makePatient();
        Sanctum::actingAs($this->makeClinician());

        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $theirs->id]))->assertForbidden();
        $this->assertSame(0, SymptomSubmission::count());
    }

    public function test_admin_can_submit_for_any_patient(): void
    {
        $p = $this->makePatient();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id]))->assertCreated();
    }

    // ---- end to end: API -> observer -> alert engine --------------------------------------------

    public function test_a_critical_checkin_over_the_api_raises_a_critical_alert(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', ['patient_id' => $p->id, 'pain' => 9, 'dyspnea' => 4])->assertCreated();

        $alert = Alert::where('patient_id', $p->id)->sole();
        $this->assertSame('pain', $alert->symptom_type);
        $this->assertSame(9, $alert->severity);
        $this->assertSame('critical', $alert->priority());
    }

    public function test_an_ordinary_checkin_raises_no_alert(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->postJson('/api/symptom-submissions', $this->scores(['patient_id' => $p->id]))->assertCreated();

        $this->assertSame(0, Alert::count());
    }

    // ---- index ----------------------------------------------------------------------------------

    public function test_history_is_newest_first_and_paginated(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        foreach ([0, 1, 2] as $d) {
            $this->checkIn($p, ['pain' => 2 + $d], Carbon::parse('2026-03-02 08:00')->addDays($d));
        }
        Sanctum::actingAs($me);

        $r = $this->getJson("/api/patients/{$p->id}/symptom-submissions?per_page=2")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(3, $r->json('total'));
        $this->assertSame(4, $r->json('data.0.pain'), 'newest first');
    }

    public function test_history_date_filters_include_the_whole_boundary_days(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        $this->checkIn($p, ['pain' => 1], Carbon::parse('2026-03-01 23:30'));
        $this->checkIn($p, ['pain' => 2], Carbon::parse('2026-03-02 06:00'));
        $this->checkIn($p, ['pain' => 3], Carbon::parse('2026-03-03 22:00'));
        $this->checkIn($p, ['pain' => 4], Carbon::parse('2026-03-04 00:30'));
        Sanctum::actingAs($me);

        $r = $this->getJson("/api/patients/{$p->id}/symptom-submissions?from=2026-03-02&to=2026-03-03")->assertOk();

        $this->assertEqualsCanonicalizing([2, 3], collect($r->json('data'))->pluck('pain')->all());
    }

    public function test_history_query_validation(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->getJson("/api/patients/{$p->id}/symptom-submissions?from=2026-03-05&to=2026-03-01")->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("/api/patients/{$p->id}/symptom-submissions?per_page=201")->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson("/api/patients/{$p->id}/symptom-submissions?from=banana")->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_history_of_another_clinicians_patient_is_forbidden(): void
    {
        $theirs = $this->makePatient();
        $this->checkIn($theirs, ['pain' => 5], Carbon::parse('2026-03-02 08:00'));
        Sanctum::actingAs($this->makeClinician());

        $this->getJson("/api/patients/{$theirs->id}/symptom-submissions")->assertForbidden();
    }
}
