@php($current = old('cancer_type', $patient->cancer_type))
<div class="row g-3">
    <div class="col-md-6">
        <label for="name" class="form-label">Full name <span class="text-danger">*</span></label>
        <input type="text" id="name" name="name" value="{{ old('name', $patient->name) }}" class="form-control @error('name') is-invalid @enderror" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="dob" class="form-label">Date of birth <span class="text-danger">*</span></label>
        <input type="date" id="dob" name="dob" max="{{ now()->toDateString() }}"
               value="{{ old('dob', $patient->dob ? \Carbon\Carbon::parse($patient->dob)->toDateString() : '') }}"
               class="form-control @error('dob') is-invalid @enderror" required>
        @error('dob')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Age is worked out from this.</div>
    </div>
    <div class="col-md-3">
        <label for="sex" class="form-label">Sex</label>
        <select id="sex" name="sex" class="form-select @error('sex') is-invalid @enderror">
            <option value="">Not recorded</option>
            @foreach ($sexes as $sex)
                <option value="{{ $sex }}" @selected(old('sex', $patient->sex) === $sex)>{{ $sex }}</option>
            @endforeach
        </select>
        @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="cancer_type" class="form-label">Type of cancer</label>
        <input type="text" id="cancer_type" name="cancer_type" list="cancer-types" value="{{ $current }}"
               class="form-control @error('cancer_type') is-invalid @enderror" placeholder="Choose or type">
        <datalist id="cancer-types">
            @foreach ($cancerTypes as $type)
                <option value="{{ $type }}">
            @endforeach
        </datalist>
        @error('cancer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="cancer_stage" class="form-label">Stage</label>
        <select id="cancer_stage" name="cancer_stage" class="form-select @error('cancer_stage') is-invalid @enderror">
            <option value="">Not recorded</option>
            @foreach ($stages as $stage)
                <option value="{{ $stage }}" @selected(old('cancer_stage', $patient->cancer_stage) === $stage)>{{ $stage === 'Unknown' ? 'Unknown' : 'Stage '.$stage }}</option>
            @endforeach
        </select>
        @error('cancer_stage')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="mobile_app_user_id" class="form-label">Mobile app ID</label>
        <input type="text" id="mobile_app_user_id" name="mobile_app_user_id" value="{{ old('mobile_app_user_id', $patient->mobile_app_user_id) }}"
               class="form-control @error('mobile_app_user_id') is-invalid @enderror">
        @error('mobile_app_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Optional. Links the patient to the phone app.</div>
    </div>

    <div class="col-12">
        <label for="diagnosis" class="form-label">Diagnosis notes</label>
        <textarea id="diagnosis" name="diagnosis" rows="3" class="form-control @error('diagnosis') is-invalid @enderror">{{ old('diagnosis', $patient->diagnosis) }}</textarea>
        @error('diagnosis')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @if ($isAdmin)
        <div class="col-md-6">
            <label for="assigned_clinician_id" class="form-label">Assigned clinician <span class="text-danger">*</span></label>
            <select id="assigned_clinician_id" name="assigned_clinician_id" class="form-select @error('assigned_clinician_id') is-invalid @enderror" required>
                <option value="">Choose</option>
                @foreach ($clinicians as $clinician)
                    <option value="{{ $clinician->id }}" @selected((int) old('assigned_clinician_id', $patient->assigned_clinician_id) === $clinician->id)>{{ $clinician->name }}</option>
                @endforeach
            </select>
            @error('assigned_clinician_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @endif
</div>
