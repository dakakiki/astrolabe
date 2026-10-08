<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AstrologerResource;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Support\Admin\Astrologers;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account help from the admin (Phase 8c): turning off two-factor sign-in for an
 * astrologer who lost their phone and recovery codes, sending the confirmation
 * email again, suspending and restoring an account. Each needs the operator's
 * password again (routes/api.php) and a reason; each is recorded in the audit
 * log with that reason and told to the astrologer by email. Admin accounts are
 * never touched from here — they are managed from the command line.
 */
class AdminAccountHelpController extends Controller
{
    public function resetTwoFactor(Request $request, User $user): AstrologerResource
    {
        $reason = $this->reason($request, $user);
        abort_unless($user->hasTwoFactorEnabled(), Response::HTTP_CONFLICT, __('admin.two_factor_off'));

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Audit::record(AuditEvent::AdminTwoFactorReset, $user, ['reason' => $reason]);
        $user->notify(new AccountNotice(AccountNotice::TWO_FACTOR_RESET, $reason));

        return $this->fresh($user);
    }

    public function resendVerification(Request $request, User $user): AstrologerResource
    {
        $reason = $this->reason($request, $user, required: false);
        abort_if($user->hasVerifiedEmail(), Response::HTTP_CONFLICT, __('admin.already_verified'));

        $user->sendEmailVerificationNotification();
        Audit::record(AuditEvent::AdminVerificationResent, $user, ['reason' => $reason]);

        return $this->fresh($user);
    }

    /** Signing in stops at once: sessions and "keep me signed in" end with it. */
    public function suspend(Request $request, User $user): AstrologerResource
    {
        $reason = $this->reason($request, $user);
        abort_if($user->isSuspended(), Response::HTTP_CONFLICT, __('admin.already_suspended'));

        DB::transaction(function () use ($user, $reason) {
            $user->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        });

        Audit::record(AuditEvent::AccountSuspended, $user, ['reason' => $reason]);
        $user->notify(new AccountNotice(AccountNotice::SUSPENDED, $reason));

        return $this->fresh($user);
    }

    public function restore(Request $request, User $user): AstrologerResource
    {
        $reason = $this->reason($request, $user, required: false);
        abort_unless($user->isSuspended(), Response::HTTP_CONFLICT, __('admin.not_suspended'));

        $user->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        Audit::record(AuditEvent::AccountRestored, $user, ['reason' => $reason]);
        $user->notify(new AccountNotice(AccountNotice::RESTORED, $reason));

        return $this->fresh($user);
    }

    /** The operator's own words; also checks that this is an astrologer's account. */
    private function reason(Request $request, User $user, bool $required = true): ?string
    {
        abort_if($user->isAdmin(), Response::HTTP_FORBIDDEN, __('admin.not_an_astrologer'));

        $data = $request->validate([
            'reason' => [$required ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        return isset($data['reason']) ? trim($data['reason']) : null;
    }

    private function fresh(User $user): AstrologerResource
    {
        return AstrologerResource::make(Astrologers::query()->where('users.id', $user->getKey())->firstOrFail());
    }
}
