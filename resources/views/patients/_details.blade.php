{{-- Patient details card + "Record check-in" form. Include near the top of patients/show.blade.php: @include('patients._details') --}}
@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <h2 class="h5 mb-0">Patient details</h2>
            <a href="{{ route('dashboard.patients.edit', $patient) }}" class="btn btn-sm btn-outline-secondary">Edit details</a>
        </div>
        <dl class="row mb-0 mt-2 small">
            <dt class="col-sm-3">Name</dt><dd class="col-sm-9">{{ $patient->name }}</dd>
            <dt class="col-sm-3">Age</dt>
            <dd class="col-sm-9">
                {{ $patient->dob ? \Carbon\Carbon::parse($patient->dob)->age.' years' : 'Not recorded' }}
                @if ($patient->dob) <span class="text-body-secondary">(born {{ \Carbon\Carbon::parse($patient->dob)->format('d M Y') }})</span> @endif
            </dd>
            <dt class="col-sm-3">Sex</dt><dd class="col-sm-9">{{ $patient->sex ?: 'Not recorded' }}</dd>
            <dt class="col-sm-3">Type of cancer</dt><dd class="col-sm-9">{{ $patient->cancer_type ?: 'Not recorded' }}</dd>
            <dt class="col-sm-3">Stage</dt>
            <dd class="col-sm-9">{{ $patient->cancer_stage ? ($patient->cancer_stage === 'Unknown' ? 'Unknown' : 'Stage '.$patient->cancer_stage) : 'Not recorded' }}</dd>
            <dt class="col-sm-3">Diagnosis notes</dt><dd class="col-sm-9">{{ $patient->diagnosis ?: 'None' }}</dd>
        </dl>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">Record a check-in</h2>
        <p class="small text-body-secondary">Enter the scores the patient reports (0 = none, 10 = worst). Leave a box empty if the symptom was not discussed. Alerts are checked as soon as you save.</p>
        @if ($errors->checkin->any())
            <div class="alert alert-danger py-2">{{ $errors->checkin->first() }}</div>
        @endif
        <form method="POST" action="{{ route('dashboard.patients.checkins.store', $patient) }}" novalidate>
            @csrf
            <div class="row g-2">
                @foreach (\App\Models\SymptomSubmission::SYMPTOM_TYPES as $type)
                    <div class="col-6 col-md-2">
                        <label for="ci-{{ $type }}" class="form-label small text-capitalize mb-1">{{ $type }}</label>
                        <input type="number" id="ci-{{ $type }}" name="{{ $type }}" min="0" max="10" inputmode="numeric"
                               value="{{ old($type) }}" class="form-control @error($type) is-invalid @enderror">
                        @error($type)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="col-12">
                    <label for="ci-notes" class="form-label small mb-1">Notes (optional)</label>
                    <input type="text" id="ci-notes" name="notes" maxlength="1900" value="{{ old('notes') }}" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3">Save check-in</button>
        </form>
    </div>
</div>
