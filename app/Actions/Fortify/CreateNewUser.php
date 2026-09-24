<?php

namespace App\Actions\Fortify;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /**
     * Register an astrologer together with the workspace they will own.
     * The SPA sends the browser's time zone and language so both start sensible.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
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
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'locale' => $validated['locale'] ?? config('app.locale'),
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $this->createWorkspace->handle($user, ['name' => $validated['workspace_name'] ?? null]);

            return $user;
        });
    }
}
