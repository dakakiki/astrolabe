<?php

namespace App\Actions\Fortify;

use App\Actions\Legal\AcceptLegalDocuments;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEvent;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Legal\LegalDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
        private readonly AcceptLegalDocuments $acceptLegalDocuments,
    ) {}

    /**
     * Register an astrologer together with the workspace they will own.
     * The SPA sends the browser's time zone and language so both start sensible.
     *
     * In the closed beta (`astrolabe.registration.mode` = "invite") the request
     * must carry a valid invitation for the same email address; it is used up
     * here, under a row lock, so one link makes exactly one account.
     *
     * The person accepts the Terms of Service and the Data Processing Agreement
     * and sees the Privacy Policy in the versions the page showed; a document
     * that changed in between is refused rather than accepted unread.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $byInvitation = self::invitationsOnly();

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'workspace_name' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'locale' => ['nullable', Rule::in(array_keys(config('astrolabe.locales')))],
            'invitation' => [$byInvitation ? 'required' : 'nullable', 'string', 'max:128'],
            // The Terms and the DPA accepted, and the privacy policy seen, in the versions on the page (Phase 8c).
            'accept_terms' => ['accepted'],
            'legal' => ['required', 'array'],
            'legal.*' => ['string', 'max:32'],
        ], [
            'invitation.required' => __('registration.invitation.required'),
            'accept_terms.accepted' => __('legal.required'),
            'legal.required' => __('legal.required'),
        ])->validate();

        $shown = array_intersect_key($validated['legal'], array_flip(LegalDocuments::slugs()));
        if ($shown != LegalDocuments::currentVersions()) {
            throw ValidationException::withMessages(['accept_terms' => __('legal.changed')]);
        }

        return DB::transaction(function () use ($validated, $byInvitation, $shown) {
            $invitation = $byInvitation ? $this->claim($validated['invitation'], $validated['email']) : null;

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'locale' => $validated['locale'] ?? config('app.locale'),
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $this->createWorkspace->handle($user, ['name' => $validated['workspace_name'] ?? null]);

            $this->acceptLegalDocuments->handle($user, $shown, 'accept_terms');

            if ($invitation !== null) {
                $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->getKey()])->save();
                Audit::record(AuditEvent::InvitationAccepted, $invitation, user: $user);
            }

            return $user;
        });
    }

    public static function invitationsOnly(): bool
    {
        return config('astrolabe.registration.mode') !== 'open';
    }

    /**
     * The invitation behind the link, locked for this transaction.
     *
     * @throws ValidationException
     */
    private function claim(string $token, string $email): RegistrationInvitation
    {
        $invitation = RegistrationInvitation::query()
            ->where('token_hash', RegistrationInvitation::hash($token))
            ->lockForUpdate()
            ->first();

        $problem = match (true) {
            $invitation === null => 'invalid',
            $invitation->status() !== RegistrationInvitation::VALID => $invitation->status(),
            ! $invitation->isFor($email) => 'other_email',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['invitation' => __('registration.invitation.'.$problem)]);
        }

        return $invitation;
    }
}
