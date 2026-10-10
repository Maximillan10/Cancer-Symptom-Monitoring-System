@extends('layouts.app')

@section('title', $patient->name)

@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Patients</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $patient->name }}</li>
        </ol>
    </nav>

    {{-- Patient header --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-5">
                    <h1 class="h3 mb-1">{{ $patient->name }}</h1>
                    <div class="text-body-secondary">{{ $patient->diagnosis ?? 'No diagnosis recorded' }}</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="small text-body-secondary">Age</div>
                    <div>{{ $patient->dob->age }} years</div>
                    <div class="small text-body-secondary">DOB {{ $patient->dob->format('d M Y') }}</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="small text-body-secondary">Clinician</div>
                    <div>{{ $patient->clinician->name ?? '-' }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-body-secondary">Last check-in</div>
                    <div>
                        @if ($latest)
                            {{ $latest->submitted_at->diffForHumans() }}
                            <div class="small text-body-secondary">{{ $latest->submitted_at->format('d M Y H:i') }}</div>
                        @else
                            None yet
                        @endif
                    </div>
                    @if ($patient->mobile_app_user_id)
                        <div class="small text-body-secondary mt-1">App ID: {{ $patient->mobile_app_user_id }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Details (cancer type and stage) and the clinician check-in form --}}
    @include('patients._details')

    {{-- Latest scores --}}
    <h2 class="h5">Latest scores</h2>
    <div class="row g-2 mb-4">
        @foreach ($types as $type)
            @php($value = $latest ? $latest->{$type} : null)
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card score-tile level-{{ \App\Support\Severity::level($value) }} h-100">
                    <div class="card-body py-2">
                        <div class="small text-body-secondary text-capitalize">{{ $type }}</div>
                        <div class="fs-3 fw-semibold">{{ $value ?? '-' }}<span class="fs-6 text-body-secondary">/10</span></div>
                        <span class="badge {{ \App\Support\Severity::badge($value) }}">{{ \App\Support\Severity::label($value) }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Range selector --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="h5 mb-0">Symptom trends</h2>
        <div class="btn-group btn-group-sm" role="group" aria-label="Date range">
            @foreach ($ranges as $range)
                <a href="{{ route('dashboard.patient', ['patient' => $patient, 'days' => $range]) }}"
                   class="btn {{ $days === $range ? 'btn-secondary' : 'btn-outline-secondary' }}">{{ $range }} days</a>
            @endforeach
        </div>
    </div>

    @if ($hasData)
        <div class="card mb-4">
            <div class="card-body">
                <div style="position: relative; height: 340px;">
                    <canvas id="trendChart" role="img" aria-label="Line chart of symptom severity over the last {{ $days }} days"></canvas>
                </div>
                <p class="small text-body-secondary mt-2 mb-0">The dashed line marks the high-severity band (7 and above).</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Symptom heatmap (daily average)</h3>
                    <span class="small text-body-secondary">0 <span class="heat-legend"></span> 10, grey = no check-in</span>
                </div>
                <div id="heatmap" class="overflow-auto"></div>
            </div>
        </div>
    @else
        <div class="card mb-4">
            <div class="card-body text-center text-body-secondary py-5">
                No check-ins in the last {{ $days }} days. Try a longer range, or wait for the patient's next submission.
            </div>
        </div>
    @endif

    <div class="row g-4">
        {{-- Recent check-ins --}}
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header bg-body">Recent check-ins</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">When</th>
                                @foreach ($types as $type)
                                    <th scope="col" class="text-center" title="{{ ucfirst($type) }}">{{ \App\Support\Severity::abbr($type) }}</th>
                                @endforeach
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($recent as $submission)
                            <tr>
                                <td class="text-nowrap">{{ $submission->submitted_at->format('d M, H:i') }}</td>
                                @foreach ($types as $type)
                                    @php($value = $submission->{$type})
                                    <td class="text-center">
                                        <span class="badge {{ \App\Support\Severity::badge($value) }}">{{ $value ?? '-' }}</span>
                                    </td>
                                @endforeach
                                <td class="small">{{ $submission->notes ?? '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-body-secondary py-4">No check-ins yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Alerts --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-body">Alerts</div>
                <ul class="list-group list-group-flush">
                    @forelse ($alerts as $alert)
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold text-capitalize">{{ $alert->symptom_type }} {{ $alert->severity }}/10</span>
                                @if ($alert->isResolved())
                                    <span class="badge text-bg-secondary">Resolved</span>
                                @else
                                    <span class="badge text-bg-danger">Open</span>
                                @endif
                            </div>
                            <div class="small text-body-secondary">
                                {{ $alert->triggered_at->format('d M Y, H:i') }} &middot; {{ $alert->threshold_breached }}
                            </div>
                            @if ($alert->isResolved())
                                <div class="small text-body-secondary">
                                    Resolved {{ $alert->resolved_at->format('d M, H:i') }}@if ($alert->resolver) by {{ $alert->resolver->name }}@endif
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-body-secondary small py-4 text-center">
                            No alerts for this patient yet.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection

@if ($hasData)
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js"></script>
<script>
    const chartData = {{ \Illuminate\Support\Js::from($chart) }};
    const heatmapData = {{ \Illuminate\Support\Js::from($heatmap) }};

    // ---- Chart.js: one line per symptom ----
    const lineColors = {
        pain: '#dc3545', fatigue: '#fd7e14', nausea: '#198754',
        anxiety: '#6f42c1', appetite: '#0d6efd', dyspnea: '#0aa2c0'
    };

    const datasets = chartData.series.map(function (s) {
        return {
            label: s.label,
            data: s.data,
            borderColor: lineColors[s.key],
            backgroundColor: lineColors[s.key],
            tension: 0.3,
            spanGaps: true,
            pointRadius: 3,
            borderWidth: 2
        };
    });

    datasets.push({
        label: 'High (7+)',
        data: chartData.labels.map(function () { return 7; }),
        borderColor: '#6c757d',
        borderDash: [6, 4],
        borderWidth: 1,
        pointRadius: 0,
        pointHoverRadius: 0
    });

    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: { labels: chartData.labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { min: 0, max: 10, ticks: { stepSize: 1 }, title: { display: true, text: 'Severity (0-10)' } }
            },
            plugins: { legend: { position: 'bottom' } }
        }
    });

    // ---- D3: symptom x day heatmap ----
    (function () {
        var days = heatmapData.days;
        var symptoms = heatmapData.symptoms;
        var values = new Map(heatmapData.cells.map(function (c) { return [c.day + '|' + c.symptom, c.value]; }));

        var cell = Math.max(12, Math.min(40, Math.floor(900 / days.length)));
        var margin = { top: 8, right: 8, bottom: 46, left: 78 };
        var width = days.length * cell;
        var height = symptoms.length * 30;

        var svg = d3.select('#heatmap')
            .append('svg')
            .attr('width', width + margin.left + margin.right)
            .attr('height', height + margin.top + margin.bottom)
            .attr('role', 'img')
            .attr('aria-label', 'Heatmap of daily average symptom scores')
            .append('g')
            .attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

        var x = d3.scaleBand().domain(days).range([0, width]).padding(0.06);
        var y = d3.scaleBand().domain(symptoms).range([0, height]).padding(0.06);
        var color = d3.scaleSequential().domain([0, 10]).interpolator(d3.interpolateYlOrRd);

        var grid = [];
        days.forEach(function (d) {
            symptoms.forEach(function (s) {
                grid.push({ day: d, symptom: s, value: values.has(d + '|' + s) ? values.get(d + '|' + s) : null });
            });
        });

        var cells = svg.selectAll('g.cell').data(grid).join('g').attr('class', 'cell');

        cells.append('rect')
            .attr('x', function (d) { return x(d.day); })
            .attr('y', function (d) { return y(d.symptom); })
            .attr('width', x.bandwidth())
            .attr('height', y.bandwidth())
            .attr('rx', 3)
            .attr('fill', function (d) { return d.value === null ? '#e9ecef' : color(d.value); })
            .append('title')
            .text(function (d) {
                return d.symptom + ', ' + d.day + ': ' + (d.value === null ? 'no check-in' : d.value + '/10');
            });

        if (x.bandwidth() >= 24) {
            cells.filter(function (d) { return d.value !== null; })
                .append('text')
                .attr('x', function (d) { return x(d.day) + x.bandwidth() / 2; })
                .attr('y', function (d) { return y(d.symptom) + y.bandwidth() / 2; })
                .attr('text-anchor', 'middle')
                .attr('dominant-baseline', 'central')
                .attr('font-size', 11)
                .attr('fill', function (d) { return d.value >= 6 ? '#ffffff' : '#212529'; })
                .text(function (d) { return Number.isInteger(d.value) ? d.value : d.value.toFixed(1); });
        }

        // Y axis
        svg.append('g').call(d3.axisLeft(y).tickSize(0)).select('.domain').remove();

        // X axis, thinned out when there are many days
        var step = Math.ceil(days.length / 15);
        svg.append('g')
            .attr('transform', 'translate(0,' + height + ')')
            .call(d3.axisBottom(x)
                .tickValues(days.filter(function (d, i) { return i % step === 0; }))
                .tickFormat(function (d) { return d.slice(8) + '/' + d.slice(5, 7); })
                .tickSize(0))
            .select('.domain').remove();

        svg.selectAll('.tick text').attr('font-size', 11).attr('fill', 'currentColor');
    })();
</script>
@endpush
@endif
