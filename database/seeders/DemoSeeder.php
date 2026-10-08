<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Simulated data only: no real patient data is used (see proposal, ethical considerations).
 * Run with: php artisan db:seed --class=DemoSeeder
 * Safe to run more than once: it will not duplicate users, patients or submissions.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(42); // reproducible simulated data

        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'System Admin', 'password' => 'password', 'role' => 'admin']
        );

        $clinicians = collect([
            ['Dr Mwansa', 'dr.mwansa@example.com'],
            ['Dr Banda', 'dr.banda@example.com'],
        ])->map(fn ($c) => User::updateOrCreate(
            ['email' => $c[1]],
            ['name' => $c[0], 'password' => 'password', 'role' => 'clinician']
        ));

        $people = [
            ['Chanda Mulenga', '1968-03-14', 'Breast cancer, stage II'],
            ['Mutale Phiri', '1975-07-02', 'Cervical cancer, stage III'],
            ['Bwalya Zulu', '1959-11-23', 'Prostate cancer, stage II'],
            ['Natasha Tembo', '1982-01-30', 'Non-Hodgkin lymphoma'],
            ['Joseph Lungu', '1971-09-09', 'Colorectal cancer, stage III'],
            ['Grace Mumba', '1990-05-18', 'Kaposi sarcoma'],
        ];

        foreach ($people as $i => [$name, $dob, $diagnosis]) {
            $patient = Patient::updateOrCreate(
                ['mobile_app_user_id' => sprintf('mobile-%03d', $i + 1)],
                [
                    'assigned_clinician_id' => $clinicians[$i % 2]->id,
                    'name' => $name,
                    'dob' => $dob,
                    'diagnosis' => $diagnosis,
                ]
            );

            if ($patient->symptomSubmissions()->exists()) {
                continue;
            }

            // Patients 0 and 3 gradually deteriorate; the rest stay stable.
            $deteriorating = in_array($i, [0, 3], true);

            for ($day = 13; $day >= 0; $day--) {
                $progress = (13 - $day) / 13;
                $row = [
                    'patient_id' => $patient->id,
                    'submitted_at' => now()->subDays($day)->setTime(9, 0),
                ];

                foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
                    $base = ($deteriorating && in_array($type, ['pain', 'fatigue', 'dyspnea'], true))
                        ? 3 + 5 * $progress
                        : 2;
                    $row[$type] = max(0, min(10, (int) round($base + mt_rand(-10, 10) / 10)));
                }

                SymptomSubmission::create($row);
            }
        }
    }
}
