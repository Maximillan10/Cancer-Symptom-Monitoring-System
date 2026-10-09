<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Patient;
use App\Services\AlertEngine;
use Illuminate\Console\Command;

class ScanAlerts extends Command
{
    protected $signature = 'alerts:scan {--fresh : Delete ALL existing alerts (including resolved ones) before scanning}';

    protected $description = 'Replay stored symptom check-ins, oldest first, through the alert engine. Use it for check-ins saved before the alert system existed.';

    public function handle(AlertEngine $engine): int
    {
        if ($this->option('fresh')) {
            Alert::query()->delete();
            $this->warn('All existing alerts were deleted.');
        }

        $created = 0;

        foreach (Patient::all() as $patient) {
            $submissions = $patient->symptomSubmissions()->orderBy('submitted_at')->get();

            foreach ($submissions as $submission) {
                $created += $engine->evaluate($submission, false)->count();
            }

            $this->line(sprintf('%-22s %d check-in(s) scanned', $patient->name, $submissions->count()));
        }

        $this->info("Scan complete: {$created} alert(s) created.");

        return self::SUCCESS;
    }
}
