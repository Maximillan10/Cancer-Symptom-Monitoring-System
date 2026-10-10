<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AlertThreshold;
use App\Models\SymptomSubmission;
use App\Services\AlertRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Each clinician's own alert thresholds. Per symptom there are two rules:
 *  - absolute:       alert when the score is at least N            (row with window_days = null)
 *  - rate of change: alert when the score rises by at least N
 *                    within D days                                  (row with window_days = D)
 * A blank box switches a rule off (the row is kept, with is_active = false).
 */
class AlertThresholdController extends Controller
{
    public function edit(Request $request): View
    {
        $clinicianId = $request->user()->id;
        $saved = AlertThreshold::where('clinician_id', $clinicianId)->get();
        $usingDefaults = $saved->isEmpty();
        $rules = $usingDefaults ? AlertThreshold::defaultRules($clinicianId) : $saved;

        $absolute = [];
        $rise = [];
        $window = [];

        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $abs = $rules->first(fn ($r) => $r->symptom_type === $type && ! $r->isRateOfChange());
            $ris = $rules->first(fn ($r) => $r->symptom_type === $type && $r->isRateOfChange());

            $absolute[$type] = ($abs && $abs->is_active) ? $abs->threshold_value : null;
            $rise[$type] = ($ris && $ris->is_active) ? $ris->threshold_value : null;
            $window[$type] = $ris ? $ris->window_days : AlertThreshold::DEFAULT_WINDOW;
        }

        return view('settings.thresholds', [
            'types' => SymptomSubmission::SYMPTOM_TYPES,
            'absolute' => $absolute,
            'rise' => $rise,
            'window' => $window,
            'usingDefaults' => $usingDefaults,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'absolute' => ['array'],
            // Capped at 9: a limit of 10 would silence a critical score of 9. Leave the box blank to switch the rule off.
            'absolute.*' => ['nullable', 'integer', 'between:1,'.AlertRules::MAX_ABSOLUTE_LIMIT],
            'rise' => ['array'],
            'rise.*' => ['nullable', 'integer', 'between:1,10'],
            'window' => ['array'],
            'window.*' => ['nullable', 'integer', 'between:1,30'],
        ]);

        $clinicianId = $request->user()->id;

        DB::transaction(function () use ($request, $clinicianId) {
            // Only the six known symptoms are read, so any extra keys in the request are ignored.
            foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
                $absolute = $this->toInt($request->input("absolute.$type"));
                $rise = $this->toInt($request->input("rise.$type"));
                $window = $this->toInt($request->input("window.$type")) ?? AlertThreshold::DEFAULT_WINDOW;

                $this->save($clinicianId, $type, null, $absolute, AlertThreshold::DEFAULT_ABSOLUTE);
                $this->save($clinicianId, $type, $window, $rise, AlertThreshold::DEFAULT_RISE);
            }
        });

        return redirect()->route('thresholds.edit')->with('status', 'Thresholds saved. They apply to new check-ins from now on.');
    }

    /** Delete this clinician's saved thresholds, so the system defaults apply again. */
    public function reset(Request $request): RedirectResponse
    {
        AlertThreshold::where('clinician_id', $request->user()->id)->delete();

        return redirect()->route('thresholds.edit')->with('status', 'Thresholds reset to the system defaults.');
    }

    private function toInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Create or update one rule row. A null $value switches the rule off but keeps the row,
     * so the clinician is not silently put back on the defaults.
     */
    private function save(int $clinicianId, string $type, ?int $windowDays, ?int $value, int $fallbackValue): void
    {
        $query = AlertThreshold::where('clinician_id', $clinicianId)->where('symptom_type', $type);

        $windowDays === null
            ? $query->whereNull('window_days')
            : $query->whereNotNull('window_days');

        $row = $query->first() ?? new AlertThreshold(['clinician_id' => $clinicianId, 'symptom_type' => $type]);

        $row->window_days = $windowDays;
        $row->is_active = $value !== null;
        $row->threshold_value = $value ?? ($row->exists ? $row->threshold_value : $fallbackValue);
        $row->save();
    }
}
