@extends('layouts.app')

@section('title', 'Summary report')

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
        <h1 class="h3 mb-0">Summary report</h1>
        <p class="text-body-secondary mb-0 small">All patients, as at {{ $generatedAt->format('d M Y, H:i') }}. Simulated data only.</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Back to patients</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['Patients', $stats['patients'], ''],
        ['Open alerts', $stats['open'], $stats['open'] > 0 ? 'text-danger' : ''],
        ['Critical open (9-10)', $stats['critical'], $stats['critical'] > 0 ? 'text-danger' : ''],
        ['Check-ins, last 7 days', $stats['checkins7'], ''],
        ['Alerts resolved, last 30 days', $stats['resolved30'], ''],
        ['No check-in for 3+ days', $stats['silent'], $stats['silent'] > 0 ? 'text-warning' : ''],
    ] as [$label, $value, $class])
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small">{{ $label }}</div>
                <div class="fs-2 fw-semibold {{ $class }}">{{ $value }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="card mb-4">
    <div class="card-header bg-body">Patients with open alerts</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Patient</th><th>Cancer</th><th>Highest open alert</th><th class="text-center">Open</th><th>Waiting since</th><th>Last check-in</th><th class="no-print"></th></tr>
            </thead>
            <tbody>
            @forelse ($attention as $row)
                <tr>
                    <td class="fw-semibold">{{ $row['patient']->name }}</td>
                    <td>{{ $row['patient']->cancer_type ?: '-' }}@if ($row['patient']->cancer_stage) <span class="text-body-secondary">, stage {{ $row['patient']->cancer_stage }}</span>@endif</td>
                    <td><span class="badge {{ \App\Support\Severity::badge($row['top_severity']) }}">{{ ucfirst($row['top_symptom']) }} {{ $row['top_severity'] }}/10</span></td>
                    <td class="text-center">{{ $row['count'] }}</td>
                    <td>{{ \Carbon\Carbon::parse($row['since'])->format('d M, H:i') }}</td>
                    <td>{{ $row['last_check_in'] ? $row['last_check_in']->diffForHumans() : 'None' }}</td>
                    <td class="text-end no-print"><a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.patient', $row['patient']) }}">Report</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-body-secondary py-4">No open alerts.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-header bg-body">Patients by stage</div>
            <ul class="list-group list-group-flush">
                @forelse ($byStage as $label => $count)
                    <li class="list-group-item d-flex justify-content-between">{{ $label === 'Unknown' ? 'Stage unknown' : ($label === 'Not recorded' ? $label : 'Stage '.$label) }}<span class="badge text-bg-secondary">{{ $count }}</span></li>
                @empty
                    <li class="list-group-item text-body-secondary">No patients.</li>
                @endforelse
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-header bg-body">Patients by cancer type</div>
            <ul class="list-group list-group-flush">
                @forelse ($byType as $label => $count)
                    <li class="list-group-item d-flex justify-content-between">{{ $label }}<span class="badge text-bg-secondary">{{ $count }}</span></li>
                @empty
                    <li class="list-group-item text-body-secondary">No patients.</li>
                @endforelse
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-header bg-body">Open alerts by symptom</div>
            <ul class="list-group list-group-flush">
                @forelse ($bySymptom as $label => $count)
                    <li class="list-group-item d-flex justify-content-between text-capitalize">{{ $label }}<span class="badge text-bg-danger">{{ $count }}</span></li>
                @empty
                    <li class="list-group-item text-body-secondary">No open alerts.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-body">No check-in for 3 days or more</div>
    <div class="card-body">
        @forelse ($silent as $patient)
            <span class="badge text-bg-light border me-1 mb-1">{{ $patient->name }} ({{ $lastCheckIn->get($patient->id) ? $lastCheckIn->get($patient->id)->diffForHumans() : 'never' }})</span>
        @empty
            <span class="text-body-secondary">Every patient has checked in during the last 3 days.</span>
        @endforelse
    </div>
</div>

<p class="small text-body-secondary">Scores run from 0 (none) to 10 (worst). Critical alerts are scores of 9 or 10. Not for clinical use.@if ($stats['average_age']) Average patient age: {{ $stats['average_age'] }} years.@endif</p>
@endsection
