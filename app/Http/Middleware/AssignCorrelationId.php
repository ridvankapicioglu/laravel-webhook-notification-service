<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the X-Correlation-ID header from the client (or creates one) and puts it into the
 * log context. Laravel carries the context into queued jobs, so the same id shows up in
 * the API logs and in the worker logs of every delivery attempt.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header(self::HEADER);

        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id)) {
            $id = (string) Str::uuid();
        }

        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
