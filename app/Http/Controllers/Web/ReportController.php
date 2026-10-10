<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Services\AlertRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Summary reports. Everything is worked out from the stored check-ins and alerts at the moment the page opens,
 * and the pages are laid out for printing, so "Print / Save as PDF" in the browser produces the PDF.
 */
class ReportController extends Controller
{
    private const RANGES = [7, 14, 30, 90];

    /** Overview of every patient. */
    public function index(): View
    {
        $patients = Patient::query()->with('clinician:id,name')->orderBy('name')->get();
        $open = Alert::open()->get();

        $lastCheckIn = SymptomSubmission::query()
            ->select('patient_id', DB::raw('max(submitted_at) as last_at'))
            ->groupBy('patient_id')
            ->pluck('last_at', 'patient_id')
            ->map(fn ($value) => Carbon::parse($value));

        // One row per patient who has open alerts, most severe first.
        $attention = $open->groupBy('patient_id')->map(function ($alerts, $patientId) use ($patients, $lastCheckIn) {
            $top = $alerts->sortByDesc('severity')->first();

            return [
                'patient' => $patients->firstWhere('id', $patientId),
                'count' => $alerts->count(),
                'top_symptom' => $top->symptom_type,
                'top_severity' => (int) $top->severity,
                'since' => $alerts->min('triggered_at'),
                'last_check_in' => $lastCheckIn->get($patientId),
            ];
        })->filter(fn ($row) => $row['patient'] !== null)
            ->sortByDesc('top_severity')->values();

        $silent = $patients->filter(function ($patient) use ($lastCheckIn) {
            $last = $lastCheckIn->get($patient->id);

            return $last === null || $last->lt(now()->subDays(3));
        })->values();

        $stats = [
            'patients' => $patients->count(),
            'open' => $open->count(),
            'critical' => $open->where('severity', '>=', AlertRules::CRITICAL_FROM)->count(),
            'checkins7' => SymptomSubmission::where('submitted_at', '>=', now()->subDays(7))->count(),
            'resolved30' => Alert::resolved()->where('resolved_at', '>=', now()->subDays(30))->count(),
            'silent' => $silent->count(),
            'average_age' => $patients->isEmpty() ? null : round($patients->avg(fn ($p) => $p->dob->age)),
        ];

        $group = fn ($items, string $field) => $items
            ->groupBy(fn ($item) => $item->{$field} ?: 'Not recorded')
            ->map->count()->sortDesc();

        return view('reports.index', [
            'stats' => $stats,
            'attention' => $attention,
            'silent' => $silent,
            'lastCheckIn' => $lastCheckIn,
            'byStage' => $group($patients, 'cancer_stage'),
            'byType' => $group($patients, 'cancer_type'),
            'bySymptom' => $open->groupBy('symptom_type')->map->count()->sortDesc(),
            'generatedAt' => now(),
        ]);
    }

    /** Summary of one patient over a chosen period. */
    public function patient(Request $request, Patient $patient): View
    {
        $days = in_array((int) $request->input('days'), self::RANGES, true) ? (int) $request->input('days') : 14;
        $since = now()->subDays($days - 1)->startOfDay();

        $submissions = $patient->symptomSubmissions()
            ->where('submitted_at', '>=', $since)
            ->orderBy('submitted_at')
            ->get();

        $rows = [];
        $peak = null; // the single highest score in the period

        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $values = $submissions->pluck($type)->filter(fn ($v) => $v !== null)->values();
            $change = $values->count() > 1 ? $values->last() - $values->first() : null;

            $rows[$type] = [
                'count' => $values->count(),
                'first' => $values->first(),
                'latest' => $values->last(),
                'average' => $values->isEmpty() ? null : round($values->avg(), 1),
                'max' => $values->max(),
                'change' => $change,
                'trend' => $change === null ? '-' : ($change >= 2 ? 'Worse' : ($change <= -2 ? 'Better' : 'Steady')),
            ];

            foreach ($submissions as $submission) {
                $score = $submission->{$type};
                if ($score !== null && ($peak === null || $score > $peak['score'])) {
                    $peak = ['symptom' => $type, 'score' => $score, 'at' => $submission->submitted_at];
                }
            }
        }

        $openAlerts = $patient->alerts()->whereNull('resolved_at')->orderByDesc('severity')->get();
        $periodAlerts = $patient->alerts()->where('triggered_at', '>=', $since)->orderByDesc('triggered_at')->get();

        // Plain statements built from the numbers above (no free-text generation).
        $findings = [];
        if ($submissions->isEmpty()) {
            $findings[] = "No check-ins were recorded in the last {$days} days.";
        } else {
            $findings[] = $submissions->count().' check-in'.($submissions->count() === 1 ? '' : 's').' recorded between '
                .$submissions->first()->submitted_at->format('d M Y').' and '.$submissions->last()->submitted_at->format('d M Y').'.';
            if ($peak) {
                $findings[] = 'Highest score: '.$peak['symptom'].' '.$peak['score'].'/10 on '.$peak['at']->format('d M Y').'.';
            }
            $worse = collect($rows)->filter(fn ($r) => $r['trend'] === 'Worse')->keys()->all();
            $better = collect($rows)->filter(fn ($r) => $r['trend'] === 'Better')->keys()->all();
            if ($worse) {
                $findings[] = 'Rising by 2 or more points since the first check-in: '.implode(', ', $worse).'.';
            }
            if ($better) {
                $findings[] = 'Falling by 2 or more points since the first check-in: '.implode(', ', $better).'.';
            }
            if (! $worse && ! $better) {
                $findings[] = 'No symptom moved by 2 or more points over the period.';
            }
        }
        $findings[] = $openAlerts->isEmpty()
            ? 'There are no open alerts.'
            : $openAlerts->count().' open alert'.($openAlerts->count() === 1 ? '' : 's').': '
                .$openAlerts->map(fn ($a) => $a->symptom_type.' '.$a->severity.'/10')->implode(', ').'.';

        $patient->load('clinician:id,name');

        return view('reports.patient', [
            'patient' => $patient,
            'days' => $days,
            'ranges' => self::RANGES,
            'since' => $since,
            'rows' => $rows,
            'findings' => $findings,
            'periodAlerts' => $periodAlerts,
            'recent' => $submissions->reverse()->take(10)->values(),
            'generatedAt' => now(),
        ]);
    }
}
