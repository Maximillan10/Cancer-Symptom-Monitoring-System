<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class DemoSeedCommandTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    public function test_it_creates_patients_check_ins_and_a_mix_of_alerts(): void
    {
        $this->clinician();
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(15, Patient::count());
        $this->assertSame(15 * 14, SymptomSubmission::count());
        $this->assertGreaterThan(0, Alert::open()->where('severity', '>=', 9)->count(), 'expected critical alerts');
        $this->assertGreaterThan(0, Alert::open()->whereBetween('severity', [7, 8])->count(), 'expected warning alerts');
        $this->assertGreaterThan(0, Alert::resolved()->count(), 'expected some resolved alerts');
        $this->assertTrue(
            Patient::whereDoesntHave('alerts')->exists(),
            'expected some patients with no alerts'
        );
    }

    public function test_it_does_nothing_when_patients_already_exist(): void
    {
        $existing = $this->patientFor($this->clinician());

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(1, Patient::count());
        $this->assertTrue(Patient::whereKey($existing->id)->exists());
    }

    public function test_fresh_replaces_existing_data_and_is_repeatable(): void
    {
        $this->patientFor($this->clinician());

        $this->artisan('demo:seed --fresh')->assertSuccessful();
        $first = Alert::count();

        $this->artisan('demo:seed --fresh')->assertSuccessful();

        $this->assertSame(15, Patient::count());
        $this->assertSame($first, Alert::count(), 'the same simulated data is produced every time');
    }

    public function test_patients_are_given_to_the_editor_account(): void
    {
        $editor = $this->clinician(['email' => 'editor@example.test']);
        config(['symptom.editor_emails' => ['editor@example.test']]);

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(15, Patient::where('assigned_clinician_id', $editor->id)->count());
    }

    public function test_it_refuses_to_run_when_there_is_no_clinician_account(): void
    {
        $this->artisan('demo:seed')->assertFailed();

        $this->assertSame(0, Patient::count());
    }
}
