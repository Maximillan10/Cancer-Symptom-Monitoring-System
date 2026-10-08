<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancer Symptom Tracker — Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark bg-dark px-4">
    <span class="navbar-brand fw-bold">Cancer Symptom Tracker</span>
    <span class="text-white-50 small">Clinician Dashboard</span>
    <form method="POST" action="/logout">
        @csrf
        <button class="btn btn-sm btn-outline-light">Logout</button>
    </form>
</nav>

<div class="container mt-4">

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center p-3 border-primary">
                <div class="text-muted small">Total patients</div>
                <div class="fs-3 fw-bold text-primary">{{ $patients->count() }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3 border-danger">
                <div class="text-muted small">Unresolved alerts</div>
                <div class="fs-3 fw-bold text-danger">
                    {{ $patients->sum(fn($p) => $p->alerts->count()) }}
                </div>
            </div>
        </div>
    </div>

    <h5 class="mb-3">Patient overview</h5>

    @if($patients->isEmpty())
        <div class="alert alert-info">No patients found. Add patients to get started.</div>
    @else
    <table class="table table-bordered table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Diagnosis</th>
                <th>Unresolved alerts</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach($patients as $patient)
            <tr>
                <td class="text-muted small">{{ $patient->id }}</td>
                <td class="fw-semibold">{{ $patient->name }}</td>
                <td>{{ $patient->diagnosis }}</td>
                <td>
                    @if($patient->alerts->count() > 0)
                        <span class="badge bg-danger fs-6">
                            {{ $patient->alerts->count() }} alert{{ $patient->alerts->count() > 1 ? 's' : '' }}
                        </span>
                    @else
                        <span class="badge bg-success">No alerts</span>
                    @endif
                </td>
                <td>
                    <a href="/patients/{{ $patient->id }}" class="btn btn-sm btn-primary">
                        View patient
                    </a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>