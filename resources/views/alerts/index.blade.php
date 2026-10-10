@extends('layouts.app')

@section('title', 'Alerts')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Alerts</h1>
            <p class="text-body-secondary mb-0 small">
                Raised automatically when a check-in crosses a threshold. Open alerts are listed most severe first.
            </p>
        </div>
        @if (\App\Support\EditorAccess::can(auth()->user()))
            <a href="{{ route('thresholds.edit') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-sliders"></i> Thresholds
            </a>
        @endif
    </div>

    <ul class="nav nav-pills mb-3">
        @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All (history)'] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $status === $key ? 'active' : '' }}" href="{{ route('alerts.index', ['status' => $key]) }}">
                    {{ $label }}
                    <span class="badge {{ $status === $key ? 'text-bg-light' : 'text-bg-secondary' }}">{{ $counts[$key] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Priority</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Symptom</th>
                        <th scope="col">Why it fired</th>
                        <th scope="col">Triggered</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($alerts as $alert)
                    <tr>
                        <td><span class="badge {{ $alert->priorityBadge() }}">{{ $alert->priorityLabel() }}</span></td>
                        <td>
                            <a href="{{ route('dashboard.patient', $alert->patient_id) }}" class="fw-semibold text-decoration-none">{{ $alert->patient->name }}</a>
                        </td>
                        <td class="text-capitalize">{{ $alert->symptom_type }} <span class="fw-semibold">{{ $alert->severity }}/10</span></td>
                        <td class="small">{{ $alert->threshold_breached }}</td>
                        <td class="small text-nowrap">
                            {{ $alert->triggered_at->format('d M Y, H:i') }}
                            <div class="text-body-secondary">{{ $alert->triggered_at->diffForHumans() }}</div>
                        </td>
                        <td class="small">
                            @if ($alert->isResolved())
                                <span class="badge text-bg-secondary">Resolved</span>
                                <div class="text-body-secondary">
                                    {{ $alert->resolved_at->format('d M, H:i') }}@if ($alert->resolver) by {{ $alert->resolver->name }}@endif
                                </div>
                            @else
                                <span class="badge text-bg-danger">Open</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if (! $alert->isResolved() && \App\Support\EditorAccess::can(auth()->user()))
                                <form method="POST" action="{{ route('alerts.resolve', $alert) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">
                                        <i class="bi bi-check2-circle"></i> Resolve
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-body-secondary py-5">
                            @if ($status === 'open')
                                No open alerts. Nothing needs attention right now.
                            @elseif ($status === 'resolved')
                                No resolved alerts yet.
                            @else
                                No alerts have been raised yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $alerts->links('pagination::bootstrap-5') }}
    </div>

    <p class="small text-body-secondary mb-0">
        Priority: critical = score 9-10, warning = 7-8, informational = below 7.
        While an alert is open, further breaches for the same patient and symptom do not create duplicates; if the score gets worse, the open alert is updated.
    </p>
@endsection
