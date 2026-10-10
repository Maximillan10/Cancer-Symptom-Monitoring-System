<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class SharedViewTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    private function lead(): User
    {
        config(['symptom.editor_emails' => ['lead@example.test']]);

        return $this->clinician(['email' => 'lead@example.test']);
    }

    public function test_every_signed_in_clinician_sees_every_patient_on_the_dashboard(): void
    {
        $lead = $this->lead();
        $other = $this->clinician();
        $patient = $this->patientFor($lead, ['name' => 'Chanda Mulenga']);

        $this->actingAs($other)->get('/dashboard')->assertOk()->assertSee('Chanda Mulenga');
    }

    public function test_a_viewer_can_open_another_clinicians_patient(): void
    {
        $lead = $this->lead();
        $viewer = $this->clinician();
        $patient = $this->patientFor($lead);

        $this->actingAs($viewer)->get(route('dashboard.patient', $patient))->assertOk();
    }

    public function test_a_viewer_still_cannot_edit_a_patient(): void
    {
        $lead = $this->lead();
        $viewer = $this->clinician();
        $patient = $this->patientFor($lead);

        $this->actingAs($viewer)->get(route('dashboard.patients.edit', $patient))->assertForbidden();
    }

    public function test_viewers_do_not_see_resolve_buttons_but_the_editor_does(): void
    {
        $lead = $this->lead();
        $viewer = $this->clinician();
        $patient = $this->patientFor($lead);
        Alert::create([
            'patient_id' => $patient->id,
            'triggered_at' => now(),
            'symptom_type' => 'pain',
            'severity' => 9,
            'threshold_breached' => 'pain 9 is at or above 8',
        ]);

        $this->actingAs($viewer)->get('/alerts')->assertOk()->assertDontSee('bi-check2-circle', false);
        $this->actingAs($lead)->get('/alerts')->assertOk()->assertSee('bi-check2-circle', false);
    }

    public function test_without_an_editor_list_clinicians_only_see_their_own_patients(): void
    {
        config(['symptom.editor_emails' => []]);
        $a = $this->clinician();
        $b = $this->clinician();
        $patient = $this->patientFor($a);

        $this->actingAs($b)->get(route('dashboard.patient', $patient))->assertForbidden();
    }
}
