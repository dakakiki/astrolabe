<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\PortalLoginToken;
use App\Models\PortalUser;
use App\Notifications\PortalSignInLink;
use App\Support\Audit\Audit;
use App\Support\Portal\PortalSessionState;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Signing in to the portal without a password (docs/spec/12, "Prijava"):
 * asking for an emailed link and code, then using one of them.
 *
 * Asking always gives the same answer, in the same time, whether the address
 * has an account or not. Link and code are single-use, short-lived, stored as
 * hashes; a wrong code counts against the request and five end it.
 */
class SignInController extends Controller
{
    /** Asking takes at least this long, so a real account is not given away by timing. */
    private const ASK_MICROSECONDS = 400_000;

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = PortalUser::normaliseEmail($data['email']);

        $this->limit('portal-sign-in-email:'.sha1($email), config('portal.limits.sign_in_per_email'));
        $this->limit('portal-sign-in-ip:'.$request->ip(), config('portal.limits.sign_in_per_ip'));

        (new Timebox)->call(function () use ($email, $request) {
            $user = PortalUser::findByEmail($email);

            if ($user !== null && $user->hasUsableAccess()) {
                [, $token, $code] = PortalLoginToken::issue($user, $request);
                $user->notify(new PortalSignInLink($token, $code));
            }
        }, self::ASK_MICROSECONDS);

        return response()->json(['message' => __('portal.sign_in.sent')], 202);
    }

    /** The emailed link: its page asks before it posts the token here. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $token = PortalLoginToken::findOpenByToken($data['token']);

        if ($token === null || ! $this->useUp($token)) {
            Audit::portal(AuditEvent::PortalSignInFailed, null, properties: ['method' => 'link']);

            throw ValidationException::withMessages(['token' => __('portal.sign_in.link_invalid')]);
        }

        return $this->signedIn($request, $token, 'link');
    }

    /** The six-digit code from the same email, typed on another device. */
    public function code(Request $request): JsonResponse
    {
        $request->merge(['code' => preg_replace('/\D+/', '', (string) $request->input('code'))]);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = PortalUser::findByEmail($data['email']);

        $token = $user === null ? null : DB::transaction(function () use ($user, $data) {
            $token = PortalLoginToken::openFor($user);

            if ($token === null) {
                return null;
            }

            $token = PortalLoginToken::query()->lockForUpdate()->find($token->getKey());

            if ($token->codeMatches($data['code'])) {
                $token->forceFill(['used_at' => now()])->save();

                return $token;
            }

            $token->increment('attempts');

            return null;
        });

        if ($token === null) {
            Audit::portal(AuditEvent::PortalSignInFailed, $user, properties: ['method' => 'code']);

            throw ValidationException::withMessages(['code' => __('portal.sign_in.code_invalid')]);
        }

        return $this->signedIn($request, $token, 'code');
    }

    private function signedIn(Request $request, PortalLoginToken $token, string $method): JsonResponse
    {
        PortalSessionState::signIn($request, $token->portalUser, $method);

        return response()->json(['data' => PortalSessionState::for($request)]);
    }

    /** Marks the link used — once, even when it is opened twice at the same moment. */
    private function useUp(PortalLoginToken $token): bool
    {
        return PortalLoginToken::query()->whereKey($token->getKey())->whereNull('used_at')->update(['used_at' => now()]) === 1;
    }

    /**
     * @param  array{0: int, 1: int}  $limit  attempts, minutes
     */
    private function limit(string $key, array $limit): void
    {
        [$attempts, $minutes] = $limit;

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw new ThrottleRequestsException(__('portal.errors.too_many'), headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, $minutes * 60);
    }
}
