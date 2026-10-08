<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $patient->name }} — Symptom Trends</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<nav class="navbar navbar-dark bg-dark px-4">
    <a href="/dashboard" class="navbar-brand fw-bold">← Back to dashboard</a>
    <span class="text-white-50 small">Cancer Symptom Tracker</span>
</nav>

<div class="container mt-4">

    <div class="mb-4">
        <h4 class="fw-bold">{{ $patient->name }}</h4>
        <span class="text-muted">{{ $patient->diagnosis }}</span>
        &nbsp;·&nbsp;
        <span class="text-muted small">DOB: {{ $patient->dob }}</span>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center p-3 border-danger">
                <div class="text-muted small">Unresolved alerts</div>
                <div class="fs-3 fw-bold text-danger">
                    {{ $patient->alerts->whereNull('resolved_at')->count() }}
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center p-3 border-primary">
                <div class="text-muted small">Total submissions</div>
                <div class="fs-3 fw-bold text-primary">
                    {{ $patient->submissions->count() }}
                </div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h6 class="mb-3">Symptom trends over time</h6>
        <canvas id="symptomChart" height="80"></canvas>
    </div>

    <div class="card p-4">
        <h6 class="mb-3">Unresolved alerts</h6>
        @php $unresolvedAlerts = $patient->alerts->whereNull('resolved_at'); @endphp
        @if($unresolvedAlerts->isEmpty())
            <p class="text-success">No unresolved alerts for this patient.</p>
        @else
        <table class="table table-sm table-bordered">
            <thead class="table-warning">
                <tr>
                    <th>Symptom</th>
                    <th>Severity</th>
                    <th>Triggered</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            @foreach($unresolvedAlerts as $alert)
                <tr>
                    <td>{{ ucfirst($alert->symptom_type) }}</td>
                    <td>
                        <span class="badge {{ $alert->severity === 'critical' ? 'bg-danger' : 'bg-warning text-dark' }}">
                            {{ ucfirst($alert->severity) }}
                        </span>
                    </td>
                    <td class="small text-muted">{{ $alert->triggered_at }}</td>
                    <td>
                        <form method="POST" action="/alerts/{{ $alert->id }}/resolve">
                            @csrf
                            <button class="btn btn-sm btn-outline-success">Acknowledge</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>

</div>

<script>
const labels   = @json($labels);
const painData = @json($painData);
const fatigue  = @json($fatigue);
const nausea   = @json($nausea);

new Chart(document.getElementById('symptomChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Pain',
                data: painData,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220,53,69,0.08)',
                tension: 0.3,
                fill: true
            },
            {
                label: 'Fatigue',
                data: fatigue,
                borderColor: '#fd7e14',
                backgroundColor: 'rgba(253,126,20,0.08)',
                tension: 0.3,
                fill: true
            },
            {
                label: 'Nausea',
                data: nausea,
                borderColor: '#6f42c1',
                backgroundColor: 'rgba(111,66,193,0.08)',
                tension: 0.3,
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            y: {
                min: 0,
                max: 10,
                title: { display: true, text: 'Severity (0–10)' }
            }
        }
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>