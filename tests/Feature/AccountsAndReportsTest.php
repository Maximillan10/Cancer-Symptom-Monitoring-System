<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class AccountsAndReportsTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    private function signUpData(array $override = []): array
    {
        return array_merge([
            'name' => 'New Clinician',
            'email' => 'new.clinician@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $override);
    }

    /** Makes lead@example.test the only editor and returns that user. */
    private function lead(): User
    {
        config(['symptom.editor_emails' => ['lead@example.test']]);

        return $this->clinician(['email' => 'lead@example.test']);
    }

    // ---- sign up ----

    public function test_the_sign_up_page_opens_for_guests(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create account');
    }

    public function test_a_new_clinician_can_sign_up_and_reach_the_dashboard(): void
    {
        $this->post('/register', $this->signUpData())->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new.clinician@example.test', 'role' => 'clinician']);
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->post('/register', $this->signUpData());

        $user = User::where('email', 'new.clinician@example.test')->firstOrFail();
        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_the_role_cannot_be_chosen_when_signing_up(): void
    {
        $this->post('/register', $this->signUpData(['role' => 'admin']));

        $this->assertSame('clinician', User::where('email', 'new.clinician@example.test')->firstOrFail()->role);
    }

    public function test_sign_up_validates_the_input(): void
    {
        $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password']);
        $this->post('/register', $this->signUpData(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->post('/register', $this->signUpData(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post('/register', $this->signUpData(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
    }

    public function test_an_email_that_is_already_registered_is_refused(): void
    {
        $this->clinician(['email' => 'taken@example.test']);

        $this->post('/register', $this->signUpData(['email' => 'TAKEN@example.test']))->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_an_editor_email_cannot_be_registered(): void
    {
        config(['symptom.editor_emails' => ['lead@example.test']]);

        $this->post('/register', $this->signUpData(['email' => 'Lead@Example.test']))->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'lead@example.test']);
    }

    public function test_signed_in_users_are_sent_away_from_the_sign_up_page(): void
    {
        $this->actingAs($this->clinician())->get('/register')->assertRedirect();
    }

    // ---- only the editor can make changes ----

    public function test_only_the_editor_can_open_or_submit_the_add_patient_form(): void
    {
        $lead = $this->lead();
        $other = $this->clinician();

        $this->actingAs($other)->get('/patients/create')->assertForbidden();
        $this->actingAs($other)->post('/patients', ['name' => 'X', 'dob' => '1980-01-01'])->assertForbidden();
        $this->assertSame(0, \App\Models\Patient::count());

        $this->actingAs($lead)->get('/patients/create')->assertOk();
    }

    public function test_the_editor_can_change_a_patient_who_belongs_to_another_clinician(): void
    {
        $lead = $this->lead();
        $patient = $this->patientFor($this->clinician());

        $this->actingAs($lead)->put("/patients/{$patient->id}", [
            'name' => 'Edited By Lead', 'dob' => '1970-02-02', 'cancer_type' => 'Lung', 'cancer_stage' => 'III',
        ])->assertRedirect();

        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'name' => 'Edited By Lead', 'cancer_stage' => 'III']);
    }

    public function test_others_cannot_edit_patients_or_record_check_ins(): void
    {
        $this->lead();
        $other = $this->clinician();
        $patient = $this->patientFor($other);

        $this->actingAs($other)->get("/patients/{$patient->id}/edit")->assertForbidden();
        $this->actingAs($other)->put("/patients/{$patient->id}", ['name' => 'Hacked', 'dob' => '1970-01-01'])->assertForbidden();
        $this->actingAs($other)->post("/patients/{$patient->id}/check-ins", ['pain' => 9])->assertForbidden();
        $this->assertDatabaseMissing('patients', ['name' => 'Hacked']);
        $this->assertDatabaseMissing('symptom_submissions', ['patient_id' => $patient->id]);
    }

    public function test_others_cannot_resolve_alerts_or_change_thresholds(): void
    {
        $lead = $this->lead();
        $patient = $this->patientFor($lead);
        $this->checkIn($patient, ['pain' => 9]);
        $alert = Alert::firstOrFail();
        $other = $this->clinician();

        $this->actingAs($other)->post("/alerts/{$alert->id}/resolve")->assertForbidden();
        $this->actingAs($other)->put('/settings/thresholds', ['absolute' => ['pain' => 5]])->assertForbidden();
        $this->assertNull($alert->fresh()->resolved_at);

        $this->actingAs($lead)->post("/alerts/{$alert->id}/resolve")->assertRedirect();
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_edit_buttons_are_hidden_from_people_who_cannot_edit(): void
    {
        $lead = $this->lead();
        $other = $this->clinician();
        $mine = $this->patientFor($other, ['name' => 'Other Olga']);
        $leads = $this->patientFor($lead, ['name' => 'Lead Lola']);

        $this->actingAs($other)->get("/patients/{$mine->id}")->assertOk()
            ->assertSee('Summary report')->assertDontSee('Record a check-in')->assertDontSee('Edit details');
        $this->actingAs($lead)->get("/patients/{$leads->id}")->assertOk()
            ->assertSee('Summary report')->assertSee('Record a check-in')->assertSee('Edit details');
    }

    public function test_the_dashboard_hides_add_patient_from_non_editors(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->clinician())->get('/dashboard')->assertOk()->assertDontSee('Add patient')->assertSee('Reports');
        $this->actingAs($lead)->get('/dashboard')->assertOk()->assertSee('Add patient')->assertSee('Reports');
    }

    public function test_when_no_editors_are_configured_everyone_keeps_edit_rights(): void
    {
        config(['symptom.editor_emails' => []]);

        $this->actingAs($this->clinician())->get('/patients/create')->assertOk();
    }

    // ---- reports ----

    public function test_guests_cannot_open_reports(): void
    {
        $patient = $this->patientFor($this->clinician());

        $this->get('/reports')->assertRedirect('/login');
        $this->get("/patients/{$patient->id}/report")->assertRedirect('/login');
    }

    public function test_the_overview_report_shows_totals_and_patients_needing_attention(): void
    {
        $me = $this->clinician();
        $sick = $this->patientFor($me, ['name' => 'Alert Alice']);
        $this->patientFor($me, ['name' => 'Quiet Quentin']);
        $this->checkIn($sick, ['pain' => 9]);

        $this->actingAs($me)->get('/reports')->assertOk()
            ->assertSee('Summary report')
            ->assertSee('Patients with open alerts')
            ->assertSee('Alert Alice')
            ->assertSee('Quiet Quentin') // never checked in, so listed under "no check-in"
            ->assertSee('Print / Save as PDF');
    }

    public function test_any_signed_in_user_can_open_the_report_of_any_patient(): void
    {
        $patient = $this->patientFor($this->clinician(), ['name' => 'Shared Sam']);
        $patient->forceFill(['cancer_type' => 'Breast', 'cancer_stage' => 'II'])->save();
        $this->checkIn($patient, ['pain' => 8, 'fatigue' => 4]);

        $this->actingAs($this->clinician())->get("/patients/{$patient->id}/report")->assertOk()
            ->assertSee('Shared Sam')
            ->assertSee('Breast')
            ->assertSee('Stage II')
            ->assertSee('Highest score: pain 8/10');
    }

    public function test_the_patient_report_handles_a_patient_with_no_check_ins(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->get("/patients/{$patient->id}/report")->assertOk()
            ->assertSee('No check-ins were recorded')
            ->assertSee('There are no open alerts');
    }

    public function test_the_report_period_can_be_changed_and_bad_values_fall_back(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->get("/patients/{$patient->id}/report?days=30")->assertOk()->assertSee('(30 days)');
        $this->actingAs($me)->get("/patients/{$patient->id}/report?days=9999")->assertOk()->assertSee('(14 days)');
    }

    public function test_the_trend_shows_a_rising_symptom(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->checkIn($patient, ['nausea' => 2], now()->subDays(3));
        $this->checkIn($patient, ['nausea' => 6], now()->subDay());

        $this->actingAs($me)->get("/patients/{$patient->id}/report")->assertOk()
            ->assertSee('Rising by 2 or more points since the first check-in: nausea.');
    }
}
