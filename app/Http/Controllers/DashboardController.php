<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Patient;

class DashboardController extends Controller
{
    public function index()
    {
        $patients = Patient::with(['alerts' => function($q) {
            $q->whereNull('resolved_at');
        }])->get();

        return view('dashboard.index', compact('patients'));
    }

    public function show($id)
    {
        $patient = Patient::findOrFail($id);

        $submissions = $patient->submissions()
            ->orderBy('submitted_at')
            ->get();

        $labels   = $submissions->map(fn($s) => $s->submitted_at->format('d M'))->toArray();
        $painData = $submissions->pluck('pain')->toArray();
        $fatigue  = $submissions->pluck('fatigue')->toArray();
        $nausea   = $submissions->pluck('nausea')->toArray();

        return view('dashboard.patient', compact('patient', 'labels', 'painData', 'fatigue', 'nausea'));
    }
}