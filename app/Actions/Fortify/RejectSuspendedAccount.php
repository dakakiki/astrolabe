<?php

namespace App\Actions\Fortify;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * A step in Fortify's sign-in (FortifyServiceProvider): an account the operator
 * suspended (Phase 8c) does not get in. Checked only once the password is
 * right, so the answer says nothing about an address without the password;
 * the attempt goes into the audit log as a failed sign-in.
 */
class RejectSuspendedAccount
{
    public function handle(Request $request, Closure $next): mixed
    {
        $credentials = [Fortify::username() => $request->input(Fortify::username()), 'password' => $request->input('password')];
        $provider = Auth::guard(config('fortify.guard'))->getProvider();

        /** @var User|null $user */
        $user = $provider->retrieveByCredentials([Fortify::username() => $credentials[Fortify::username()]]);

        if ($user?->isSuspended() && $provider->validateCredentials($user, $credentials)) {
            Audit::record(AuditEvent::LoginFailed, properties: ['suspended' => true], user: $user);

            throw ValidationException::withMessages([Fortify::username() => __('admin.suspended')]);
        }

        return $next($request);
    }
}
