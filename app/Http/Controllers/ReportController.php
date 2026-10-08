<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Models\Alert;
use App\Models\Report;

class ReportController extends Controller
{
    public function generate($patientId)
    {
        $patient = Patient::findOrFail($patientId);

        $submissions = SymptomSubmission::where('patient_id', $patient->id)
            ->orderBy('submitted_at')
            ->get();

        $content = [
            'total_submissions' => $submissions->count(),
            'avg_pain'          => round($submissions->avg('pain'), 1),
            'avg_fatigue'       => round($submissions->avg('fatigue'), 1),
            'avg_nausea'        => round($submissions->avg('nausea'), 1),
            'avg_anxiety'       => round($submissions->avg('anxiety'), 1),
            'avg_appetite'      => round($submissions->avg('appetite'), 1),
            'avg_dyspnea'       => round($submissions->avg('dyspnea'), 1),
            'alerts_count'      => Alert::where('patient_id', $patient->id)->count(),
            'resolved_alerts'   => Alert::where('patient_id', $patient->id)->whereNotNull('resolved_at')->count(),
            'generated_at'      => now()->toDateTimeString(),
        ];

        Report::create([
            'patient_id'   => $patient->id,
            'generated_by' => auth()->id(),
            'generated_at' => now(),
            'report_type'  => 'symptom_summary',
            'content_json' => $content,
        ]);

        return view('reports.show', compact('patient', 'content', 'submissions'));
    }

    public function index()
    {
        $reports = Report::with('patient')
            ->orderBy('generated_at', 'desc')
            ->get();

        return view('reports.index', compact('reports'));
    }
}