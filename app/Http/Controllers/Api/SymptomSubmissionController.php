<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class SymptomSubmissionController extends Controller
{
    /** GET /api/patients/{patient}/symptom-submissions?from=&to=&per_page= */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $submissions = $patient->symptomSubmissions()
            ->when($request->filled('from'), fn ($q) => $q->where('submitted_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('submitted_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('submitted_at')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json($submissions);
    }

    /**
     * POST /api/symptom-submissions
     * Data sync endpoint for the companion mobile app (Part A).
     * The patient is identified by patient_id OR mobile_app_user_id.
     */
    public function store(Request $request): JsonResponse
    {
        $rules = [
            'patient_id' => ['required_without:mobile_app_user_id', 'nullable', 'integer', 'exists:patients,id'],
            'mobile_app_user_id' => ['required_without:patient_id', 'nullable', 'string', 'exists:patients,mobile_app_user_id'],
            'submitted_at' => ['nullable', 'date', 'before_or_equal:+10 minutes'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $rules[$type] = ['nullable', 'integer', 'between:0,10'];
        }

        $data = $request->validate($rules);

        $hasScore = collect(SymptomSubmission::SYMPTOM_TYPES)
            ->contains(fn ($type) => ! is_null($data[$type] ?? null));

        if (! $hasScore) {
            return response()->json([
                'message' => 'At least one symptom score (0-10) is required.',
                'errors' => ['symptoms' => ['Provide at least one of: '.implode(', ', SymptomSubmission::SYMPTOM_TYPES)]],
            ], 422);
        }

        $patient = ! empty($data['patient_id'])
            ? Patient::findOrFail($data['patient_id'])
            : Patient::where('mobile_app_user_id', $data['mobile_app_user_id'])->firstOrFail();

        Gate::authorize('view', $patient);

        $submission = $patient->symptomSubmissions()->create([
            'submitted_at' => $data['submitted_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            ...Arr::only($data, SymptomSubmission::SYMPTOM_TYPES),
        ]);

        return response()->json($submission, 201);
    }
}