public function test_alert_created_when_threshold_exceeded(): void {
    $clinician = User::factory()->create(['role' => 'clinician']);
    $patient = Patient::factory()->create(['assigned_clinician_id' => $clinician->id]);

    AlertThreshold::create([
        'clinician_id' => $clinician->id,
        'symptom_type' => 'pain',
        'threshold_value' => 7,
        'window_days' => 7,
        'is_active' => true,
    ]);

    SymptomSubmission::create([
        'patient_id' => $patient->id,
        'submitted_at' => now(),
        'pain' => 9, 'fatigue'=>1,'nausea'=>1,'anxiety'=>1,'appetite'=>1,'dyspnea'=>1,
    ]);

    (new AlertEngine())->checkPatient($patient);

    $this->assertDatabaseHas('alerts', [
        'patient_id' => $patient->id,
        'symptom_type' => 'pain',
    ]);
}