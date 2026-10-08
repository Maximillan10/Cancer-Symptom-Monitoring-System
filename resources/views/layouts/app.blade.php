<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') | Symptom Tracker</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root { --brand: #0f766e; }
        .brand-nav { background-color: var(--brand); }
        .stat-card .stat-value { font-size: 2rem; font-weight: 600; line-height: 1.1; }
        .score-tile { border-left: 4px solid var(--bs-border-color); }
        .score-tile.level-high { border-left-color: var(--bs-danger); }
        .score-tile.level-moderate { border-left-color: var(--bs-warning); }
        .score-tile.level-low { border-left-color: var(--bs-success); }
        .table-patients tbody tr { cursor: pointer; }
        .heat-legend { display: inline-block; width: 140px; height: 10px; border-radius: 5px;
            background: linear-gradient(to right, #ffffcc, #fd8d3c, #800026); vertical-align: middle; }
    </style>
</head>
<body class="bg-body-tertiary">

<nav class="navbar navbar-expand-md navbar-dark brand-nav mb-4">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
            <i class="bi bi-heart-pulse"></i> Symptom Tracker
        </a>
        <div class="d-flex align-items-center gap-3 ms-auto text-white">
            <span class="small">
                <i class="bi bi-person-circle"></i> {{ auth()->user()->name }}
                <span class="badge text-bg-light text-capitalize">{{ auth()->user()->role }}</span>
            </span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-light">Log out</button>
            </form>
        </div>
    </div>
</nav>

<main class="container pb-5">
    @yield('content')
</main>

<footer class="container pb-4 text-center small text-body-secondary">
    Simulated data only. Not for clinical use.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
