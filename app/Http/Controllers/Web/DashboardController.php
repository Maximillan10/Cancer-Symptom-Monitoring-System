<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Support\EditorAccess;
use App\Support\Severity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->input('search', ''));

        // Everyone sees every patient when a shared dashboard is on (EDITOR_EMAILS set) and for admins;
        // otherwise a clinician sees their own patients.
        $seeAll = $user->isAdmin() || EditorAccess::sharedView();

        $all = Patient::query()
            ->when(! $seeAll, fn ($q) => $q->where('assigned_clinician_id', $user->id))
            ->with('latestSubmission')
            ->withCount('unresolvedAlerts')
            ->get();

        // Triage order: highest current symptom score first, then name.
        $rows = $all->map(function (Patient $patient) {
            $latest = $patient->latestSubmission;
            $top = Severity::top($latest);

            return [
                'patient' => $patient,
                'latest' => $latest,
                'top' => $top,
                'score' => $top['score'] ?? -1,
            ];
        })->sort(function ($a, $b) {
            return [$b['score'], $a['patient']->name] <=> [$a['score'], $b['patient']->name];
        })->values();

        $stats = [
            'patients' => $all->count(),
            'checkins' => SymptomSubmission::whereIn('patient_id', $all->pluck('id'))
                ->where('submitted_at', '>=', now()->subDays(7))
                ->count(),
            'high' => $rows->where('score', '>=', Severity::HIGH)->count(),
            'alerts' => (int) $all->sum('unresolved_alerts_count'),
        ];

        $visible = $search === ''
            ? $rows
            : $rows->filter(fn ($r) => Str::contains(Str::lower($r['patient']->name), Str::lower($search)))->values();

        return view('dashboard', [
            'rows' => $visible,
            'stats' => $stats,
            'search' => $search,
            'types' => SymptomSubmission::SYMPTOM_TYPES,
        ]);
    }
}
