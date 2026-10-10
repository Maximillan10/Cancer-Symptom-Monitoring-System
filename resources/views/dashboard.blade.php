@extends('layouts.app')

@section('title', 'Patients')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Patients</h1>
            <p class="text-body-secondary mb-0 small">Sorted by highest current symptom score, so the patients who need attention first appear at the top.</p>
        </div>
        <form method="GET" action="{{ route('dashboard') }}" class="d-flex gap-2" role="search">
            <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="Search by name" aria-label="Search patients by name">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i><span class="visually-hidden">Search</span></button>
            @if ($search !== '')
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Clear</a>
            @endif
            <a href="{{ route('reports.index') }}" class="btn btn-outline-primary text-nowrap"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
            @if (\App\Support\EditorAccess::can(auth()->user()))
                <a href="{{ route('dashboard.patients.create') }}" class="btn btn-primary text-nowrap"><i class="bi bi-person-plus"></i> Add patient</a>
            @endif
        </form>
    </div>

    {{-- Summary cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="text-body-secondary small">Patients</div>
                <div class="stat-value">{{ $stats['patients'] }}</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="text-body-secondary small">Check-ins (last 7 days)</div>
                <div class="stat-value">{{ $stats['checkins'] }}</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="text-body-secondary small">High symptom score now</div>
                <div class="stat-value {{ $stats['high'] > 0 ? 'text-danger' : '' }}">{{ $stats['high'] }}</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="text-body-secondary small">Unresolved alerts</div>
                <div class="stat-value">{{ $stats['alerts'] }}</div>
            </div></div>
        </div>
    </div>

    {{-- Patient table --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-patients">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Patient</th>
                        <th scope="col">Diagnosis</th>
                        <th scope="col">Last check-in</th>
                        <th scope="col">Latest scores (0-10)</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Alerts</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr onclick="window.location='{{ route('dashboard.patient', $row['patient']) }}'">
                        <td>
                            <a href="{{ route('dashboard.patient', $row['patient']) }}" class="fw-semibold text-decoration-none">{{ $row['patient']->name }}</a>
                            <div class="small text-body-secondary">{{ $row['patient']->dob->age }} years</div>
                        </td>
                        <td>
                            {{ $row['patient']->cancer_type ?: ($row['patient']->diagnosis ?? '-') }}
                            @if ($row['patient']->cancer_stage)
                                <div class="small text-body-secondary">{{ $row['patient']->cancer_stage === 'Unknown' ? 'Stage unknown' : 'Stage '.$row['patient']->cancer_stage }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($row['latest'])
                                <span title="{{ $row['latest']->submitted_at->format('d M Y H:i') }}">{{ $row['latest']->submitted_at->diffForHumans() }}</span>
                            @else
                                <span class="text-body-secondary">No check-ins yet</span>
                            @endif
                        </td>
                        <td>
                            @foreach ($types as $type)
                                @php($value = $row['latest'] ? $row['latest']->{$type} : null)
                                <span class="badge {{ \App\Support\Severity::badge($value) }}"
                                      title="{{ ucfirst($type) }}: {{ $value ?? 'no data' }}">{{ \App\Support\Severity::abbr($type) }} {{ $value ?? '-' }}</span>
                            @endforeach
                        </td>
                        <td>
                            @php($topScore = $row['top'] ? $row['top']['score'] : null)
                            <span class="badge {{ \App\Support\Severity::badge($topScore) }}">{{ \App\Support\Severity::label($topScore) }}</span>
                            @if ($row['top'])
                                <div class="small text-body-secondary">{{ ucfirst($row['top']['type']) }} {{ $row['top']['score'] }}</div>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($row['patient']->unresolved_alerts_count > 0)
                                <span class="badge text-bg-danger">{{ $row['patient']->unresolved_alerts_count }}</span>
                            @else
                                <span class="text-body-secondary">0</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-body-secondary py-5">
                            @if ($search !== '')
                                No patients match "{{ $search }}".
                            @else
                                No patients assigned to you yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="small text-body-secondary mt-3 mb-0">
        Score key: Pa pain, Fa fatigue, Na nausea, An anxiety, Ap appetite, Dy dyspnea. Higher is more severe.
        Colours: green 0-3, amber 4-6, red 7-10.
    </p>
@endsection
