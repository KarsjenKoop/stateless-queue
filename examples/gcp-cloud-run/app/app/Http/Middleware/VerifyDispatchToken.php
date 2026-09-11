<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the example's dispatch routes with a shared token.
 *
 * These routes publish to real Pub/Sub topics, so leaving them open would let anyone who finds the
 * service URL run up a bill. Compared with hash_equals() for the same reason the package compares
 * its own webhook secret that way — a plain === leaks the token through response timing.
 */
class VerifyDispatchToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('app.dispatch_token');

        if (! is_string($expected) || $expected === '') {
            return response()->json(['error' => 'Dispatch token is not configured'], 503);
        }

        $provided = $request->header('X-Dispatch-Token');

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
