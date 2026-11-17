<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware: runs before routing and before any database access.
 */
final class BearerTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ledger.api_token', '');
        $header = (string) $request->headers->get('Authorization', '');

        // Fail closed if no token is configured.
        if ($expected === '' || !str_starts_with($header, 'Bearer ')) {
            return ApiException::unauthorized()->render();
        }

        $given = substr($header, 7);

        // Hash both sides so the comparison is constant time and does not leak
        // the token length (hash_equals returns early on a length mismatch).
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $given))) {
            return ApiException::unauthorized()->render();
        }

        return $next($request);
    }
}
