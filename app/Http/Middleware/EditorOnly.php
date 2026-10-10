<?php

namespace App\Http\Middleware;

use App\Support\EditorAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks anyone who is not an editor from routes that change data. */
class EditorOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(EditorAccess::can($request->user()), 403, 'Only the lead clinician can make changes.');

        return $next($request);
    }
}
