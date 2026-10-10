<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    /** GET /api/patients?search=&per_page= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Patient::class);
        $user = $request->user();

        $patients = Patient::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('assigned_clinician_id', $user->id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->with('latestSubmission')
            ->withCount('unresolvedAlerts')
            ->orderBy('name')
            ->paginate(max(1, min((int) $request->input('per_page', 15), 100)));

        return response()->json($patients);
    }

    /** POST /api/patients */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Patient::class);
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sex' => ['nullable', 'in:Female,Male,Other'],
'cancer_type' => ['nullable', 'string', 'max:100'],
'cancer_stage' => ['nullable', 'in:I,II,III,IV,Unknown'],
            'dob' => ['required', 'date', 'before:today'],
            'diagnosis' => ['nullable', 'string', 'max:255'],
            'mobile_app_user_id' => ['nullable', 'string', 'max:100', 'unique:patients,mobile_app_user_id'],
            'assigned_clinician_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Only admins may assign a patient to a different clinician.
        $data['assigned_clinician_id'] = ($user->isAdmin() && ! empty($data['assigned_clinician_id']))
            ? $data['assigned_clinician_id']
            : $user->id;

        $patient = Patient::create($data);

        return response()->json($patient->load('clinician:id,name,email'), 201);
    }

    /** GET /api/patients/{patient} */
    public function show(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $patient->load(['clinician:id,name,email', 'latestSubmission'])->loadCount('unresolvedAlerts');

        return response()->json($patient);
    }

    /** PUT/PATCH /api/patients/{patient} */
    public function update(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('update', $patient);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sex' => ['nullable', 'in:Female,Male,Other'],
'cancer_type' => ['nullable', 'string', 'max:100'],
'cancer_stage' => ['nullable', 'in:I,II,III,IV,Unknown'],
            'dob' => ['sometimes', 'required', 'date', 'before:today'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mobile_app_user_id' => [
                'sometimes', 'nullable', 'string', 'max:100',
                Rule::unique('patients', 'mobile_app_user_id')->ignore($patient->id),
            ],
            'assigned_clinician_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        if (! $request->user()->isAdmin()) {
            unset($data['assigned_clinician_id']);
        }

        $patient->update($data);

        return response()->json($patient->fresh()->load('clinician:id,name,email'));
    }

    /** DELETE /api/patients/{patient} */
    public function destroy(Patient $patient): Response
    {
        Gate::authorize('delete', $patient);

        $patient->delete();

        return response()->noContent();
    }
}
