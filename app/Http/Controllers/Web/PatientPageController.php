<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PatientPageController extends Controller
{
    private const RANGES = [7, 14, 30, 90];

    public function show(Request $request, Patient $patient): View
    {
        Gate::authorize('view', $patient); // a clinician can only open their own patients

        $days = in_array((int) $request->input('days'), self::RANGES, true)
            ? (int) $request->input('days')
            : 14;

        $since = now()->subDays($days - 1)->startOfDay();
        $types = SymptomSubmission::SYMPTOM_TYPES;

        $submissions = $patient->symptomSubmissions()
            ->where('submitted_at', '>=', $since)
            ->orderBy('submitted_at')
            ->get();

        $latest = $patient->symptomSubmissions()->latest('submitted_at')->first();
        $recent = $patient->symptomSubmissions()->latest('submitted_at')->limit(10)->get();
        // Open alerts first, then the most recent resolved ones.
        $alerts = $patient->alerts()->with('resolver:id,name')
            ->orderByRaw('resolved_at is not null')
            ->orderByDesc('triggered_at')
            ->limit(10)
            ->get();
        $patient->load('clinician:id,name');

        // Chart.js line chart: one line per symptom. If a day has several check-ins, show the time too.
        $oneCheckInPerDay = $submissions->map(fn ($s) => $s->submitted_at->format('Y-m-d'))->unique()->count() === $submissions->count();
        $chart = [
            'labels' => $submissions->map(fn ($s) => $s->submitted_at->format($oneCheckInPerDay ? 'd M' : 'd M H:i'))->all(),
            'series' => collect($types)->map(fn ($type) => [
                'key' => $type,
                'label' => ucfirst($type),
                'data' => $submissions->map(fn ($s) => $s->{$type})->all(),
            ])->values()->all(),
        ];

        // D3 heatmap: symptom x day, average score per day. Every day in the range is included, so gaps are visible.
        $byDay = $submissions->groupBy(fn ($s) => $s->submitted_at->format('Y-m-d'));
        $heatDays = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $heatDays[] = $d->format('Y-m-d');
        }

        $cells = [];
        foreach ($byDay as $day => $group) {
            foreach ($types as $type) {
                $values = $group->pluck($type)->filter(fn ($v) => $v !== null);
                if ($values->isNotEmpty()) {
                    $cells[] = ['day' => $day, 'symptom' => ucfirst($type), 'value' => round($values->avg(), 1)];
                }
            }
        }

        $heatmap = [
            'days' => $heatDays,
            'symptoms' => array_map('ucfirst', $types),
            'cells' => $cells,
        ];

        return view('patients.show', [
            'patient' => $patient,
            'days' => $days,
            'ranges' => self::RANGES,
            'types' => $types,
            'latest' => $latest,
            'recent' => $recent,
            'alerts' => $alerts,
            'chart' => $chart,
            'heatmap' => $heatmap,
            'hasData' => $submissions->isNotEmpty(),
        ]);
    }
}
