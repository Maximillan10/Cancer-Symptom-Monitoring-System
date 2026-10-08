<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Models\User;

class PatientSeeder extends Seeder
{
    public function run(): void
    {
        $clinician = User::first();

        if (!$clinician) {
            $clinician = User::create([
                'name'     => 'Dr. Test Clinician',
                'email'    => 'clinician@test.com',
                'password' => bcrypt('password'),
                'role'     => 'clinician',
            ]);
        }

        Patient::factory(20)->create([
            'assigned_clinician_id' => $clinician->id,
        ]);

        foreach (Patient::all() as $patient) {
            for ($i = 0; $i < 14; $i++) {
                SymptomSubmission::create([
                    'patient_id'   => $patient->id,
                    'submitted_at' => now()->subDays($i),
                    'pain'         => rand(1, 10),
                    'fatigue'      => rand(1, 10),
                    'nausea'       => rand(1, 10),
                    'anxiety'      => rand(1, 10),
                    'appetite'     => rand(1, 10),
                    'dyspnea'      => rand(1, 10),
                ]);
            }
        }
    }
}