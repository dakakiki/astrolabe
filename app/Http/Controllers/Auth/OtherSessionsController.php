<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sessions of the signed-in user on other browsers and devices
 * (Settings → Security). Relies on the database session driver.
 */
class OtherSessionsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $this->otherSessions($request)->count()]]);
    }

    public function destroy(Request $request): Response
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $this->otherSessions($request)->delete();

        // A "keep me signed in" cookie elsewhere would otherwise sign that device back in.
        $request->user()->setRememberToken(Str::random(60));
        $request->user()->save();

        return response()->noContent();
    }

    private function otherSessions(Request $request): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('id', '!=', $request->session()->getId());
    }
}
