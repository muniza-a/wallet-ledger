<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /health: the API is up and can reach MySQL.
 * Behind the bearer token like every other endpoint.
 */
final class HealthController
{
    /**
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1');
        } catch (Throwable) {
            return ApiException::json(503, 'service_unavailable', 'Database is unreachable.');
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
