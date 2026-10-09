<?php

namespace Database\Factories;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'locale' => 'en',
            'timezone' => 'Europe/Belgrade',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An astrologer has accepted the Terms and the DPA in force and seen the
     * privacy policy, as registration records it (Phase 8c). A test about a
     * new version deletes these rows or publishes one.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->is_admin) {
                return;
            }

            foreach (LegalDocuments::currentVersions() as $document => $version) {
                LegalAcceptance::query()->create([
                    'user_id' => $user->getKey(),
                    'document' => $document,
                    'version' => $version,
                    'accepted_at' => now(),
                ]);
            }
        });
    }

    /**
     * Give the user a workspace they own, as registration does.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withWorkspace(array $attributes = []): static
    {
        return $this->afterCreating(function (User $user) use ($attributes) {
            app(CreateWorkspace::class)->handle($user, $attributes);
        });
    }

    /**
     * The operator's admin (Phase 8c): no practice, two-factor sign-in on —
     * as `admin:create` makes it and the admin requires. `twoFactor: false`
     * leaves it as it is right after `admin:create`.
     */
    public function admin(bool $twoFactor = true): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'two_factor_secret' => $twoFactor ? encrypt('JBSWY3DPEHPK3PXP') : null,
            'two_factor_recovery_codes' => $twoFactor ? encrypt(json_encode(['recovery-one', 'recovery-two'])) : null,
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
