@extends('layouts.app')

@section('title', 'Report: '.$patient->name)

@section('content')
<style>
    @media print {
        .navbar, footer, .no-print { display: none !important; }
        body { background: #fff !important; }
        .card { break-inside: avoid; box-shadow: none !important; }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Patient summary report</h1>
        <p class="text-body-secondary mb-0 small">Generated {{ $generatedAt->format('d M Y, H:i') }}. Period: {{ $since->format('d M Y') }} to {{ $generatedAt->format('d M Y') }} ({{ $days }} days).</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <div class="btn-group" role="group" aria-label="Period">
            @foreach ($ranges as $range)
                <a href="{{ route('reports.patient', ['patient' => $patient, 'days' => $range]) }}" class="btn btn-sm {{ $days === $range ? 'btn-secondary' : 'btn-outline-secondary' }}">{{ $range }} days</a>
            @endforeach
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h4 mb-3">{{ $patient->name }}</h2>
        <dl class="row mb-0 small">
            <dt class="col-sm-3">Age</dt><dd class="col-sm-9">{{ $patient->dob->age }} years (born {{ $patient->dob->format('d M Y') }})</dd>
            <dt class="col-sm-3">Sex</dt><dd class="col-sm-9">{{ $patient->sex ?: 'Not recorded' }}</dd>
            <dt class="col-sm-3">Type of cancer</dt><dd class="col-sm-9">{{ $patient->cancer_type ?: 'Not recorded' }}</dd>
            <dt class="col-sm-3">Stage</dt><dd class="col-sm-9">{{ $patient->cancer_stage ? ($patient->cancer_stage === 'Unknown' ? 'Unknown' : 'Stage '.$patient->cancer_stage) : 'Not recorded' }}</dd>
            <dt class="col-sm-3">Diagnosis notes</dt><dd class="col-sm-9">{{ $patient->diagnosis ?: 'None' }}</dd>
            <dt class="col-sm-3">Clinician</dt><dd class="col-sm-9">{{ $patient->clinician->name ?? '-' }}</dd>
        </dl>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-body">Key points</div>
    <ul class="list-group list-group-flush">
        @foreach ($findings as $finding)
            <li class="list-group-item">{{ $finding }}</li>
        @endforeach
    </ul>
</div>

<div class="card mb-4">
    <div class="card-header bg-body">Symptom summary (scores 0 to 10)</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Symptom</th><th class="text-center">Check-ins</th><th class="text-center">First</th><th class="text-center">Latest</th><th class="text-center">Average</th><th class="text-center">Highest</th><th class="text-center">Change</th><th>Trend</th></tr>
            </thead>
            <tbody>
            @foreach ($rows as $type => $row)
                <tr>
                    <th scope="row" class="text-capitalize">{{ $type }}</th>
                    <td class="text-center">{{ $row['count'] }}</td>
                    <td class="text-center">{{ $row['first'] ?? '-' }}</td>
                    <td class="text-center">@if ($row['latest'] !== null)<span class="badge {{ \App\Support\Severity::badge($row['latest']) }}">{{ $row['latest'] }}</span>@else - @endif</td>
                    <td class="text-center">{{ $row['average'] ?? '-' }}</td>
                    <td class="text-center">{{ $row['max'] ?? '-' }}</td>
                    <td class="text-center">{{ $row['change'] === null ? '-' : ($row['change'] > 0 ? '+' : '').$row['change'] }}</td>
                    <td>{{ $row['trend'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-body">Alerts in this period</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Raised</th><th>Symptom</th><th class="text-center">Score</th><th>Rule</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($periodAlerts as $alert)
                <tr>
                    <td class="text-nowrap">{{ $alert->triggered_at->format('d M Y, H:i') }}</td>
                    <td class="text-capitalize">{{ $alert->symptom_type }}</td>
                    <td class="text-center"><span class="badge {{ \App\Support\Severity::badge($alert->severity) }}">{{ $alert->severity }}</span></td>
                    <td class="small">{{ $alert->threshold_breached }}</td>
                    <td>{{ $alert->isResolved() ? 'Resolved '.$alert->resolved_at->format('d M') : 'Open' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-body-secondary py-3">No alerts in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-body">Latest check-ins</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>When</th>@foreach ($rows as $type => $row)<th class="text-center text-capitalize" title="{{ $type }}">{{ \App\Support\Severity::abbr($type) }}</th>@endforeach<th>Notes</th></tr>
            </thead>
            <tbody>
            @forelse ($recent as $submission)
                <tr>
                    <td class="text-nowrap">{{ $submission->submitted_at->format('d M, H:i') }}</td>
                    @foreach ($rows as $type => $row)
                        <td class="text-center">{{ $submission->{$type} ?? '-' }}</td>
                    @endforeach
                    <td class="small">{{ $submission->notes ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-body-secondary py-3">No check-ins in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-body-secondary">Scores are as reported by the patient or entered by a clinician: 0 is none, 10 is the worst. A higher score is worse for every symptom. Simulated data only. Not for clinical use.</p>
@endsection
