@extends('layouts.app')

@section('title', 'Alert thresholds')

@section('content')
    <div class="mb-3">
        <h1 class="h3 mb-0">Alert thresholds</h1>
        <p class="text-body-secondary mb-0 small">
            Choose when an alert is raised for your patients. Each time a patient's check-in arrives it is compared with these rules.
        </p>
    </div>

    @if ($usingDefaults)
        <div class="alert alert-info">
            You have not saved your own thresholds yet, so the system defaults are in use
            (score of 7 or more, or a rise of 3 or more within 3 days). Change anything below and press Save to use your own.
        </div>
    @endif

    <form method="POST" action="{{ route('thresholds.update') }}" novalidate>
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Symptom</th>
                            <th scope="col">Alert when the score is at least</th>
                            <th scope="col">Or when it rises by at least</th>
                            <th scope="col">Within (days)</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($types as $type)
                        <tr>
                            <th scope="row" class="text-capitalize">{{ $type }}</th>
                            <td style="max-width: 160px;">
                                <input type="number" min="1" max="9" inputmode="numeric" placeholder="Off"
                                       name="absolute[{{ $type }}]" value="{{ old('absolute.'.$type, $absolute[$type]) }}"
                                       class="form-control form-control-sm @error('absolute.'.$type) is-invalid @enderror"
                                       aria-label="{{ ucfirst($type) }}: alert at this score or higher">
                                @error('absolute.'.$type)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                            <td style="max-width: 160px;">
                                <input type="number" min="1" max="10" inputmode="numeric" placeholder="Off"
                                       name="rise[{{ $type }}]" value="{{ old('rise.'.$type, $rise[$type]) }}"
                                       class="form-control form-control-sm @error('rise.'.$type) is-invalid @enderror"
                                       aria-label="{{ ucfirst($type) }}: alert when the score rises by this many points">
                                @error('rise.'.$type)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                            <td style="max-width: 160px;">
                                <input type="number" min="1" max="30" inputmode="numeric"
                                       name="window[{{ $type }}]" value="{{ old('window.'.$type, $window[$type]) }}"
                                       class="form-control form-control-sm @error('window.'.$type) is-invalid @enderror"
                                       aria-label="{{ ucfirst($type) }}: number of days for the rise">
                                @error('window.'.$type)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save thresholds</button>
            <a href="{{ route('alerts.index') }}" class="btn btn-outline-secondary">Back to alerts</a>
        </div>
    </form>

    <div class="card mb-3">
        <div class="card-body small">
            <h2 class="h6">How the rules work</h2>
            <ul class="mb-0">
                <li>Scores run from 0 to 10, and a higher score is worse. Leave a box empty to switch that rule off.</li>
                <li>The "rises by" rule compares the new score with the lowest score for that symptom in the last few days.</li>
                <li>Only new check-ins are checked, and only the patient's newest one, so old check-ins synced late from a phone do not raise alerts.</li>
                <li>To avoid alert fatigue, there is one open alert per patient and symptom. A worse score updates it, and resolving it lets the next breach raise a new one.</li>
                <li>Priority comes from the score: critical for 9-10, warning for 7-8, informational below 7.</li>
                <li>These thresholds apply to the patients assigned to you.</li>
            </ul>
        </div>
    </div>

    @unless ($usingDefaults)
        <form method="POST" action="{{ route('thresholds.reset') }}"
              onsubmit="return confirm('Remove your saved thresholds and go back to the system defaults?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger">Reset to system defaults</button>
        </form>
    @endunless
@endsection
