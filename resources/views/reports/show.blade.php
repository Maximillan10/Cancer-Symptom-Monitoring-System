<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report — {{ $patient->name }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<nav class="navbar navbar-dark bg-dark px-4">
    <a href="/dashboard" class="navbar-brand fw-bold">← Back to dashboard</a>
    <span class="text-white-50 small">Patient Report</span>
</nav>

<div class="container mt-4">

    <div class="mb-4">
        <h4 class="fw-bold">Symptom summary report</h4>
        <p class="text-muted">Patient: <strong>{{ $patient->name }}</strong>
        &nbsp;·&nbsp; {{ $patient->diagnosis }}
        &nbsp;·&nbsp; Generated: {{ $content['generated_at'] }}</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center p-3 border-primary">
                <div class="text-muted small">Submissions</div>
                <div class="fs-4 fw-bold text-primary">{{ $content['total_submissions'] }}</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center p-3 border-danger">
                <div class="text-muted small">Total alerts</div>
                <div class="fs-4 fw-bold text-danger">{{ $content['alerts_count'] }}</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center p-3 border-success">
                <div class="text-muted small">Resolved</div>
                <div class="fs-4 fw-bold text-success">{{ $content['resolved_alerts'] }}</div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h6 class="mb-3">Average symptom scores</h6>
        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr>
                    <th>Symptom</th>
                    <th>Average score (out of 10)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach(['pain','fatigue','nausea','anxiety','appetite','dyspnea'] as $symptom)
                <tr>
                    <td>{{ ucfirst($symptom) }}</td>
                    <td>{{ $content['avg_' . $symptom] }}</td>
                    <td>
                        @if($content['avg_' . $symptom] >= 7)
                            <span class="badge bg-danger">High</span>
                        @elseif($content['avg_' . $symptom] >= 4)
                            <span class="badge bg-warning text-dark">Moderate</span>
                        @else
                            <span class="badge bg-success">Low</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card p-4 mb-4">
        <h6 class="mb-3">Symptom trend chart</h6>
        <canvas id="reportChart" height="80"></canvas>
    </div>

    <a href="/dashboard" class="btn btn-secondary mb-4">Back to dashboard</a>

</div>

<script>
const labels = @json($submissions->map(fn($s) => \Carbon\Carbon::parse($s->submitted_at)->format('d M')));
new Chart(document.getElementById('reportChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            { label: 'Pain',    data: @json($submissions->pluck('pain')),    borderColor: '#dc3545', tension: 0.3, fill: false },
            { label: 'Fatigue', data: @json($submissions->pluck('fatigue')), borderColor: '#fd7e14', tension: 0.3, fill: false },
            { label: 'Nausea',  data: @json($submissions->pluck('nausea')),  borderColor: '#6f42c1', tension: 0.3, fill: false },
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { min: 0, max: 10, title: { display: true, text: 'Severity (0–10)' } } }
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>