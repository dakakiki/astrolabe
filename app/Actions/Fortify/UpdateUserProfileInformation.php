<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Name, email and the user's own regional preferences. Each settings
     * section sends only its fields; name and email are required when sent.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'locale' => ['sometimes', 'required', Rule::in(array_keys(config('astrolabe.locales')))],
            'timezone' => ['sometimes', 'required', 'timezone:all_with_bc'],
        ])->validate();

        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        $user->forceFill($validated);

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }
    }
}
