<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class PatientManagementTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    private function details(array $override = []): array
    {
        return array_merge([
            'name' => 'Chanda Mulenga',
            'dob' => '1968-03-14',
            'sex' => 'Female',
            'cancer_type' => 'Breast',
            'cancer_stage' => 'III',
            'diagnosis' => 'Invasive ductal carcinoma',
        ], $override);
    }

    public function test_guests_cannot_reach_the_patient_forms(): void
    {
        $this->get('/patients/create')->assertRedirect('/login');
        $this->post('/patients', $this->details())->assertRedirect('/login');
    }

    public function test_the_add_patient_form_opens(): void
    {
        $this->actingAs($this->clinician())->get('/patients/create')->assertOk()->assertSee('Type of cancer');
    }

    public function test_a_clinician_can_add_a_patient_with_cancer_details(): void
    {
        $me = $this->clinician();

        $response = $this->actingAs($me)->post('/patients', $this->details());

        $patient = Patient::where('name', 'Chanda Mulenga')->firstOrFail();
        $response->assertRedirect(route('dashboard.patient', $patient));
        $this->assertSame($me->id, $patient->assigned_clinician_id);
        $this->assertDatabaseHas('patients', [
            'id' => $patient->id, 'cancer_type' => 'Breast', 'cancer_stage' => 'III', 'sex' => 'Female',
        ]);
    }

    public function test_the_new_patient_appears_on_the_dashboard(): void
    {
        $me = $this->clinician();
        $this->actingAs($me)->post('/patients', $this->details());

        $this->actingAs($me)->get('/dashboard')->assertOk()->assertSee('Chanda Mulenga');
    }

    public function test_the_patient_page_shows_age_type_and_stage(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me, ['name' => 'Detail Dina', 'dob' => now()->subYears(40)->toDateString()]);
        // forceFill: the new columns may not be in Patient::$fillable, and create() would silently drop them.
        $patient->forceFill(['cancer_type' => 'Cervical', 'cancer_stage' => 'II'])->save();

        // Needs @include('patients._details') in patients/show.blade.php (README step 4).
        $this->actingAs($me)->get("/patients/{$patient->id}")->assertOk()
            ->assertSee('40 years')->assertSee('Cervical')->assertSee('Stage II');
    }

    public function test_adding_a_patient_validates_the_input(): void
    {
        $me = $this->clinician();

        $this->actingAs($me)->post('/patients', [])->assertSessionHasErrors(['name', 'dob']);
        $this->actingAs($me)->post('/patients', $this->details(['dob' => now()->addDay()->toDateString()]))->assertSessionHasErrors('dob');
        $this->actingAs($me)->post('/patients', $this->details(['cancer_stage' => 'V']))->assertSessionHasErrors('cancer_stage');
        $this->assertSame(0, Patient::count());
    }

    public function test_a_clinician_cannot_assign_a_new_patient_to_someone_else(): void
    {
        $me = $this->clinician();
        $other = $this->clinician();

        $this->actingAs($me)->post('/patients', $this->details(['assigned_clinician_id' => $other->id]));

        $this->assertSame($me->id, Patient::firstOrFail()->assigned_clinician_id);
    }

    public function test_an_admin_must_choose_a_clinician_and_can_assign_one(): void
    {
        $clinician = $this->clinician();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/patients', $this->details())->assertSessionHasErrors('assigned_clinician_id');
        $this->actingAs($admin)->post('/patients', $this->details(['assigned_clinician_id' => $clinician->id]))->assertSessionHasNoErrors();

        $this->assertSame($clinician->id, Patient::firstOrFail()->assigned_clinician_id);
    }

    public function test_a_clinician_can_edit_their_own_patient(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->get("/patients/{$patient->id}/edit")->assertOk();
        $this->actingAs($me)->put("/patients/{$patient->id}", $this->details(['name' => 'Renamed', 'cancer_stage' => 'IV']))
            ->assertRedirect(route('dashboard.patient', $patient));

        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'name' => 'Renamed', 'cancer_stage' => 'IV', 'assigned_clinician_id' => $me->id]);
    }

    public function test_a_clinician_cannot_edit_or_check_in_for_another_clinicians_patient(): void
    {
        $me = $this->clinician();
        $theirs = $this->patientFor($this->clinician());

        $this->actingAs($me)->get("/patients/{$theirs->id}/edit")->assertForbidden();
        $this->actingAs($me)->put("/patients/{$theirs->id}", $this->details())->assertForbidden();
        $this->actingAs($me)->post("/patients/{$theirs->id}/check-ins", ['pain' => 5])->assertForbidden();
        $this->assertSame(0, SymptomSubmission::count());
    }

    public function test_a_clinician_can_record_a_check_in_and_it_is_stored_with_a_note(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->post("/patients/{$patient->id}/check-ins", ['pain' => 4, 'fatigue' => 3, 'notes' => 'Clinic visit'])
            ->assertRedirect(route('dashboard.patient', $patient));

        $submission = SymptomSubmission::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame(4, $submission->pain);
        $this->assertNull($submission->nausea);
        $this->assertStringContainsString($me->name, $submission->notes);
        $this->assertStringContainsString('Clinic visit', $submission->notes);
        $this->assertSame(0, Alert::count()); // scores below every limit
    }

    public function test_a_high_score_recorded_by_a_clinician_raises_an_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->post("/patients/{$patient->id}/check-ins", ['pain' => 9]);

        $this->assertDatabaseHas('alerts', ['patient_id' => $patient->id, 'symptom_type' => 'pain']);
    }

    public function test_a_check_in_needs_at_least_one_score_and_valid_ranges(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->post("/patients/{$patient->id}/check-ins", [])->assertSessionHasErrors('pain', null, 'checkin');
        $this->actingAs($me)->post("/patients/{$patient->id}/check-ins", ['pain' => 11])->assertSessionHasErrors('pain');
        $this->actingAs($me)->post("/patients/{$patient->id}/check-ins", ['pain' => -1])->assertSessionHasErrors('pain');
        $this->assertSame(0, SymptomSubmission::count());
    }
}
