<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AlertController extends Controller
{
    private const STATUSES = ['open', 'resolved', 'all'];

    /** Alert list with Open / Resolved / All tabs. Resolved + All together are the alert history. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $status = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : 'open';

        $visible = Alert::query()->visibleTo($user);

        $counts = [
            'open' => (clone $visible)->open()->count(),
            'resolved' => (clone $visible)->resolved()->count(),
        ];
        $counts['all'] = $counts['open'] + $counts['resolved'];

        $query = (clone $visible)->with(['patient:id,name', 'resolver:id,name']);

        if ($status === 'open') {
            // Most severe first; among equals, the one that has waited longest.
            $query->open()->orderByDesc('severity')->orderBy('triggered_at');
        } elseif ($status === 'resolved') {
            $query->resolved()->orderByDesc('resolved_at');
        } else {
            $query->orderByDesc('triggered_at');
        }

        return view('alerts.index', [
            'alerts' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    /** Acknowledge/resolve an alert. Records who did it and when. */
    public function resolve(Request $request, Alert $alert): RedirectResponse
    {
        // A clinician can only act on alerts for their own patients.
        Gate::authorize('view', $alert->patient);

        if ($alert->isResolved()) {
            return back()->with('status', 'That alert was already resolved.');
        }

        $alert->resolve($request->user());

        return back()->with('status', 'Alert resolved.');
    }
}
