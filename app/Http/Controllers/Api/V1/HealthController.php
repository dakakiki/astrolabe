<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Operations\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * For an uptime monitor: 200 when every check passes, 503 when one fails.
 * Public, so it says only yes or no per part — no versions, counts or errors.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $checks = $health->run();
        $ok = ! in_array(false, $checks, true);

        return response()
            ->json(['data' => ['status' => $ok ? 'ok' : 'failing', 'checks' => $checks]], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
