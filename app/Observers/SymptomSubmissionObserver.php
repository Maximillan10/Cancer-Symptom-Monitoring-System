<?php

namespace App\Observers;

use App\Models\SymptomSubmission;
use App\Services\AlertEngine;
use Throwable;

/**
 * Runs the alert engine whenever a symptom check-in is stored, whether it came from the mobile
 * sync API, the seeder or tinker. Registered on the model with #[ObservedBy].
 */
class SymptomSubmissionObserver
{
    public function __construct(private AlertEngine $engine)
    {
    }

    public function created(SymptomSubmission $submission): void
    {
        // The check-in is already saved. A fault in alert evaluation must never make the
        // mobile app's sync request fail, so log it and carry on.
        try {
            $this->engine->evaluate($submission);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
