<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the system with simulated patients and check-ins so the dashboard is not empty.
 *
 * Simulated data only: no real patient information is used. Every check-in is stored through the
 * normal model, so the alert observer runs exactly as it does for the mobile app and the alerts
 * you see on the dashboard are produced by the real alert rules, not inserted by hand.
 *
 *   php artisan demo:seed            adds the demo patients, only when no patients exist yet
 *   php artisan demo:seed --fresh    deletes ALL patients, check-ins and alerts first, then rebuilds
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed {--fresh : Delete all existing patients, check-ins and alerts first}';

    protected $description = 'Create about 15 simulated patients with check-ins so the dashboard shows alerts and trends';

    /** Days of history per patient, ending with today's check-in. */
    private const DAYS = 14;

    /**
     * name, sex, dob, cancer type, stage, diagnosis note, profile.
     * Profiles: stable | mild | rising (fast rise, raises the rise rule) | high (reaches 7-8) |
     * critical (reaches 9-10) | recovering (high early, improving, so alerts get resolved by hand later).
     */
    private const PATIENTS = [
        ['Chanda Mulenga',   'Female', '1968-03-14', 'Breast cancer',       'II',  'Post-surgery, on chemotherapy',          'rising'],
        ['Mutale Phiri',     'Female', '1975-07-02', 'Cervical cancer',     'III', 'Chemoradiation in progress',             'critical'],
        ['Bwalya Zulu',      'Male',   '1959-11-23', 'Prostate cancer',     'II',  'Hormone therapy',                        'stable'],
        ['Natasha Tembo',    'Female', '1982-01-30', 'Lymphoma',            'II',  'Non-Hodgkin lymphoma, cycle 3',          'high'],
        ['Joseph Lungu',     'Male',   '1971-09-09', 'Colorectal cancer',   'III', 'After resection, adjuvant therapy',      'mild'],
        ['Grace Mumba',      'Female', '1990-05-18', 'Kaposi sarcoma',      'I',   'On antiretroviral and chemotherapy',     'stable'],
        ['Peter Banda',      'Male',   '1954-02-11', 'Lung cancer',         'IV',  'Palliative care, breathing difficulty',  'critical'],
        ['Esther Chisenga',  'Female', '1963-08-27', 'Breast cancer',       'I',   'Radiotherapy completed',                 'recovering'],
        ['Moses Kasonde',    'Male',   '1948-12-05', 'Oesophageal cancer',  'III', 'Difficulty eating, nutrition support',   'high'],
        ['Rose Nyirenda',    'Female', '1979-04-19', 'Cervical cancer',     'II',  'Follow-up after treatment',              'stable'],
        ['Kelvin Sakala',    'Male',   '1986-10-03', 'Leukaemia',           'II',  'Maintenance chemotherapy',               'rising'],
        ['Agnes Bwalya',     'Female', '1957-06-21', 'Ovarian cancer',      'III', 'Second-line chemotherapy',               'high'],
        ['Daniel Mwape',     'Male',   '1966-01-15', 'Liver cancer',        'IV',  'Pain management',                        'critical'],
        ['Precious Zimba',   'Female', '1992-09-30', 'Lymphoma',            'I',   'Hodgkin lymphoma, responding well',      'mild'],
        ['Stephen Chilufya', 'Male',   '1973-03-08', 'Colorectal cancer',   'II',  'Routine monitoring',                     'recovering'],
    ];

    public function handle(): int
    {
        if ($this->option('fresh')) {
            DB::transaction(function () {
                Alert::query()->delete();
                SymptomSubmission::query()->delete();
                Patient::query()->delete();
            });
            $this->warn('All existing patients, check-ins and alerts were deleted.');
        } elseif (Patient::query()->exists()) {
            $this->warn('Patients already exist, so nothing was added. Use --fresh to replace them with the demo data.');

            return self::SUCCESS;
        }

        $clinician = $this->clinician();

        if (! $clinician) {
            $this->error('There is no clinician account to give the patients to. Create the editor account first: set EDITOR_EMAILS and EDITOR_PASSWORD, then run php artisan app:ensure-editor.');

            return self::FAILURE;
        }

        mt_srand(2026); // the same simulated data every time, so a demo is repeatable

        foreach (self::PATIENTS as $i => [$name, $sex, $dob, $type, $stage, $note, $profile]) {
            $patient = Patient::create([
                'assigned_clinician_id' => $clinician->id,
                'name' => $name,
                'dob' => $dob,
                'sex' => $sex,
                'cancer_type' => $type,
                'cancer_stage' => $stage,
                'diagnosis' => $note,
                'mobile_app_user_id' => sprintf('demo-%03d', $i + 1),
            ]);

            // Oldest first, so the alert engine sees the history the way it would arrive in real life.
            for ($day = self::DAYS - 1; $day >= 0; $day--) {
                SymptomSubmission::create(array_merge(
                    $this->scores($profile, (self::DAYS - 1 - $day) / (self::DAYS - 1)),
                    [
                        'patient_id' => $patient->id,
                        'submitted_at' => now()->subDays($day)->setTime(8 + ($i % 4), 15 * ($i % 4)),
                        'notes' => $day === 0 ? $this->note($profile) : null,
                    ]
                ));
            }

            // A recovering patient was reviewed when the dose was changed, so the early alerts are already resolved.
            if ($profile === 'recovering') {
                $patient->alerts()->open()->get()->each->resolve($clinician);
            }
        }

        $patients = Patient::count();
        $checkIns = SymptomSubmission::count();
        $open = Alert::open()->count();

        $this->info("Created {$patients} patients with {$checkIns} check-ins.");
        $this->info("The alert engine raised {$open} open alert(s), and the dashboard now has data.");
        $this->line("Patients are assigned to {$clinician->name} ({$clinician->email}).");

        return self::SUCCESS;
    }

    /** The editor account if there is one, otherwise the first clinician. Never creates an account. */
    private function clinician(): ?User
    {
        $editor = (string) (config('symptom.editor_emails')[0] ?? '');

        if ($editor !== '' && ($user = User::where('email', $editor)->first())) {
            return $user;
        }

        return User::where('role', 'clinician')->orderBy('id')->first();
    }

    /**
     * Six scores for one day. $progress runs from 0 (oldest day) to 1 (today).
     * Each profile shapes the trend; a little random noise keeps the lines from looking drawn by a ruler.
     */
    private function scores(string $profile, float $progress): array
    {
        $scores = [];

        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $focus = in_array($type, ['pain', 'fatigue', 'dyspnea'], true);

            $base = match ($profile) {
                'stable' => 1.5,
                'mild' => $focus ? 3.0 + 1.5 * $progress : 2.0,
                // Low, then a sharp rise over the last three days, which is what the rise rule watches for.
                'rising' => $focus ? 2.0 + 5.5 * max(0, ($progress - 0.78) / 0.22) : 2.0,
                'high' => $focus ? 3.0 + 4.5 * $progress : 2.5,
                'critical' => $focus ? 3.5 + 6.0 * $progress : 3.0 + 2.0 * $progress,
                // Bad early on, better now.
                'recovering' => $focus ? 7.5 - 5.0 * $progress : 3.0 - 1.0 * $progress,
                default => 2.0,
            };

            $scores[$type] = max(0, min(10, (int) round($base + mt_rand(-5, 5) / 10)));
        }

        // Make the final day deterministic for the profiles that are meant to alert,
        // so a demo always shows the same critical and warning cases.
        if ($profile === 'critical') {
            $scores['pain'] = max($scores['pain'], 9);
            $scores['dyspnea'] = max($scores['dyspnea'], 8);
        } elseif ($profile === 'high' && $progress === 1.0) {
            $scores['pain'] = max($scores['pain'], 7);
        }

        return $scores;
    }

    private function note(string $profile): ?string
    {
        return match ($profile) {
            'critical' => 'Severe symptoms today, needs urgent review.',
            'high' => 'Symptoms getting worse over the past week.',
            'rising' => 'Sudden worsening over the last few days.',
            'recovering' => 'Feeling better since the dose was adjusted.',
            default => null,
        };
    }
}
