<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StatusController extends Controller
{
    /**
     * Public liveness summary used by the SPA shell. Deliberately reveals
     * nothing beyond whether the backing services answer.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'app' => config('app.name'),
                'laravel' => app()->version(),
                'database' => $this->databaseIsReachable(),
            ],
        ]);
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable $e) {
            Log::warning('Status check: database unreachable', ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
