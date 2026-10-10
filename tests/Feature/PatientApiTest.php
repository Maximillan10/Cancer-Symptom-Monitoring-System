<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    public function test_patients_require_authentication(): void
    {
        $this->getJson('/api/patients')->assertUnauthorized();
        $this->postJson('/api/patients', [])->assertUnauthorized();
    }

    public function test_a_clinician_lists_only_their_own_patients(): void
    {
        $me = $this->clinician();
        $other = $this->clinician();
        $mine = $this->patientFor($me, ['name' => 'Mine']);
        $this->patientFor($other, ['name' => 'Theirs']);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/patients')->assertOk();
        $this->assertSame([$mine->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_an_admin_lists_every_patient(): void
    {
        $this->patientFor($this->clinician());
        $this->patientFor($this->clinician());

        Sanctum::actingAs($this->admin());

        $this->assertCount(2, $this->getJson('/api/patients')->json('data'));
    }

    public function test_the_list_is_paginated_and_per_page_is_capped_at_100(): void
    {
        $me = $this->clinician();
        foreach (range(1, 3) as $i) {
            $this->patientFor($me);
        }

        Sanctum::actingAs($me);

        $this->getJson('/api/patients?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 3);
        $this->assertSame(100, $this->getJson('/api/patients?per_page=5000')->json('per_page'));
    }

    public function test_a_clinician_can_create_a_patient_assigned_to_themselves(): void
    {
        $me = $this->clinician();
        Sanctum::actingAs($me);

        $this->postJson('/api/patients', ['name' => 'New Patient', 'dob' => '1980-05-02', 'diagnosis' => 'Breast cancer'])
            ->assertCreated()
            ->assertJsonPath('assigned_clinician_id', $me->id);
    }

    public function test_a_clinician_cannot_assign_a_patient_to_someone_else(): void
    {
        $me = $this->clinician();
        $other = $this->clinician();
        Sanctum::actingAs($me);

        $this->postJson('/api/patients', [
            'name' => 'Sneaky', 'dob' => '1980-05-02', 'assigned_clinician_id' => $other->id,
        ])->assertCreated()->assertJsonPath('assigned_clinician_id', $me->id);
    }

    public function test_an_admin_can_assign_a_patient_to_a_clinician(): void
    {
        $clinician = $this->clinician();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/patients', [
            'name' => 'Assigned', 'dob' => '1980-05-02', 'assigned_clinician_id' => $clinician->id,
        ])->assertCreated()->assertJsonPath('assigned_clinician_id', $clinician->id);
    }

    public function test_creating_a_patient_validates_the_input(): void
    {
        Sanctum::actingAs($this->clinician());

        $this->postJson('/api/patients', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'dob']);
        $this->postJson('/api/patients', ['name' => 'Future', 'dob' => now()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('dob');
    }

    public function test_mobile_app_user_id_must_be_unique(): void
    {
        $me = $this->clinician();
        $this->patientFor($me, ['mobile_app_user_id' => 'app-1']);
        Sanctum::actingAs($me);

        $this->postJson('/api/patients', ['name' => 'Dup', 'dob' => '1980-01-01', 'mobile_app_user_id' => 'app-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile_app_user_id');
    }

    public function test_a_clinician_can_view_their_own_patient(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->getJson("/api/patients/{$patient->id}")->assertOk()->assertJsonPath('id', $patient->id);
    }

    public function test_a_clinician_cannot_view_another_clinicians_patient(): void
    {
        $patient = $this->patientFor($this->clinician());
        Sanctum::actingAs($this->clinician());

        $this->getJson("/api/patients/{$patient->id}")->assertForbidden();
    }

    public function test_a_missing_patient_is_a_404(): void
    {
        Sanctum::actingAs($this->clinician());

        $this->getJson('/api/patients/999999')->assertNotFound();
    }

    public function test_a_clinician_can_update_their_patient_but_not_reassign_them(): void
    {
        $me = $this->clinician();
        $other = $this->clinician();
        $patient = $this->patientFor($me);
        Sanctum::actingAs($me);

        $this->putJson("/api/patients/{$patient->id}", ['name' => 'Renamed', 'assigned_clinician_id' => $other->id])
            ->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('assigned_clinician_id', $me->id);
    }

    public function test_a_clinician_cannot_update_another_clinicians_patient(): void
    {
        $patient = $this->patientFor($this->clinician());
        Sanctum::actingAs($this->clinician());

        $this->putJson("/api/patients/{$patient->id}", ['name' => 'Hijack'])->assertForbidden();
        $this->assertDatabaseMissing('patients', ['name' => 'Hijack']);
    }

    public function test_a_clinician_can_delete_their_patient_but_not_anyone_elses(): void
    {
        $me = $this->clinician();
        $mine = $this->patientFor($me);
        $theirs = $this->patientFor($this->clinician());
        Sanctum::actingAs($me);

        $this->deleteJson("/api/patients/{$theirs->id}")->assertForbidden();
        $this->assertDatabaseHas('patients', ['id' => $theirs->id]);

        $this->deleteJson("/api/patients/{$mine->id}")->assertNoContent();
        $this->assertDatabaseMissing('patients', ['id' => $mine->id]);
    }

    public function test_the_patient_list_shows_the_number_of_unresolved_alerts(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->checkIn($patient, ['pain' => 9]);
        Sanctum::actingAs($me);

        $this->getJson('/api/patients')->assertJsonPath('data.0.unresolved_alerts_count', 1);
    }
}
