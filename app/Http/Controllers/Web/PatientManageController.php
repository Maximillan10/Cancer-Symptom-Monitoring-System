<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\SymptomSubmission;
use App\Http\Middleware\EditorOnly;
use App\Models\User;
use App\Support\EditorAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Lets a clinician register and edit patients from the dashboard, and record a check-in for a patient
 * (for example during a clinic visit). A check-in saved here goes through the same path as one from the
 * mobile app, so the alert engine runs on it and any alert appears straight away.
 */
class PatientManageController extends Controller implements HasMiddleware
{
    /** Every action here changes data, so only editors (see config/symptom.php) may use them. */
    public static function middleware(): array
    {
        return [new Middleware(EditorOnly::class)];
    }

    public const STAGES = ['I', 'II', 'III', 'IV', 'Unknown'];

    public const SEXES = ['Female', 'Male', 'Other'];

    public const CANCER_TYPES = [
        'Breast', 'Cervical', 'Prostate', 'Colorectal', 'Lung', 'Kaposi sarcoma', 'Oesophageal',
        'Liver', 'Stomach', 'Lymphoma', 'Leukaemia', 'Other',
    ];

    public function create(Request $request): View
    {
        return view('patients.create', $this->formData($request, new Patient));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules($request));

        $patient = new Patient;
        $this->fill($patient, $data, $request);
        $patient->save();

        return redirect()->route('dashboard.patient', $patient)->with('status', "{$patient->name} was added.");
    }

    public function edit(Request $request, Patient $patient): View
    {
        $this->authorizeOwner($request, $patient);

        return view('patients.edit', $this->formData($request, $patient));
    }

    public function update(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorizeOwner($request, $patient);

        $data = $request->validate($this->rules($request, $patient));
        $this->fill($patient, $data, $request);
        $patient->save();

        return redirect()->route('dashboard.patient', $patient)->with('status', 'Patient details saved.');
    }

    /** Record a check-in on the patient's behalf. Needs at least one symptom score. */
    public function storeCheckIn(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorizeOwner($request, $patient);

        $rules = ['notes' => ['nullable', 'string', 'max:1900']];
        foreach (SymptomSubmission::SYMPTOM_TYPES as $type) {
            $rules[$type] = ['nullable', 'integer', 'between:0,10'];
        }

        $data = $request->validate($rules);
        $scores = array_filter(
            array_intersect_key($data, array_flip(SymptomSubmission::SYMPTOM_TYPES)),
            fn ($v) => $v !== null
        );

        if ($scores === []) {
            return back()->withInput()->withErrors(['pain' => 'Enter at least one symptom score from 0 to 10.'], 'checkin');
        }

        $note = 'Recorded by '.$request->user()->name.'.';
        if (! empty($data['notes'])) {
            $note .= ' '.$data['notes'];
        }

        $submission = new SymptomSubmission;
        $submission->forceFill($scores + [
            'patient_id' => $patient->id,
            'submitted_at' => now(),
            'notes' => $note,
        ]);
        $submission->save(); // the observer runs the alert engine here

        return redirect()->route('dashboard.patient', $patient)->with('status', 'Check-in recorded.');
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()->role === 'admin';
    }

    private function authorizeOwner(Request $request, Patient $patient): void
    {
        // When editors are configured, an editor may change any patient. Otherwise a clinician only their own.
        if (EditorAccess::isRestricted()) {
            return;
        }

        abort_unless($this->isAdmin($request) || $patient->assigned_clinician_id === $request->user()->id, 403);
    }

    private function rules(Request $request, ?Patient $patient = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'dob' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'sex' => ['nullable', Rule::in(self::SEXES)],
            'cancer_type' => ['nullable', 'string', 'max:100'],
            'cancer_stage' => ['nullable', Rule::in(self::STAGES)],
            'diagnosis' => ['nullable', 'string', 'max:1000'],
            'mobile_app_user_id' => ['nullable', 'string', 'max:255', Rule::unique('patients', 'mobile_app_user_id')->ignore($patient?->id)],
        ];

        if ($this->isAdmin($request)) {
            $rules['assigned_clinician_id'] = ['required', Rule::exists('users', 'id')->where('role', 'clinician')];
        }

        return $rules;
    }

    private function fill(Patient $patient, array $data, Request $request): void
    {
        // forceFill so this works whether or not the new columns are listed in Patient::$fillable.
        $patient->forceFill([
            'name' => $data['name'],
            'dob' => $data['dob'],
            'sex' => $data['sex'] ?? null,
            'cancer_type' => $data['cancer_type'] ?? null,
            'cancer_stage' => $data['cancer_stage'] ?? null,
            'diagnosis' => $data['diagnosis'] ?? null,
            'mobile_app_user_id' => $data['mobile_app_user_id'] ?? null,
            // A clinician can only ever assign to themselves; only an admin may choose.
            'assigned_clinician_id' => $this->isAdmin($request)
                ? $data['assigned_clinician_id']
                : ($patient->exists ? $patient->assigned_clinician_id : $request->user()->id),
        ]);
    }

    private function formData(Request $request, Patient $patient): array
    {
        return [
            'patient' => $patient,
            'stages' => self::STAGES,
            'sexes' => self::SEXES,
            'cancerTypes' => self::CANCER_TYPES,
            'isAdmin' => $this->isAdmin($request),
            'clinicians' => $this->isAdmin($request) ? User::where('role', 'clinician')->orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }
}
