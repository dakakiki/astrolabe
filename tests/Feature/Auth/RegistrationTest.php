<?php

namespace Tests\Feature\Auth;

use App\Enums\HouseSystem;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Enums\ZodiacMode;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', $overrides + [
            'name' => 'Mila Vega',
            'email' => 'mila@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'workspace_name' => 'Vega Astrology',
            'timezone' => 'Europe/Belgrade',
            'locale' => 'en',
        ]);
    }

    public function test_registration_creates_the_user_and_an_owned_workspace(): void
    {
        Notification::fake();

        $this->register()->assertCreated();

        $user = User::where('email', 'mila@example.com')->firstOrFail();
        $workspace = $user->currentWorkspace;

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Europe/Belgrade', $user->timezone);
        $this->assertSame('Vega Astrology', $workspace->name);
        $this->assertSame('Europe/Belgrade', $workspace->timezone);
        $this->assertSame(HouseSystem::Placidus, $workspace->default_house_system);
        $this->assertSame(ZodiacMode::Tropical, $workspace->default_zodiac_mode);
        $this->assertSame(WorkspaceRole::Owner, $user->roleIn($workspace));
        $this->assertSame(MembershipStatus::Active, $user->workspaces()->first()->membership->status);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_a_missing_practice_name_gets_a_default(): void
    {
        $this->register(['workspace_name' => null])->assertCreated();

        $this->assertSame("Mila Vega's practice", User::firstOrFail()->currentWorkspace->name);
    }

    public function test_the_verification_link_opens_the_spa(): void
    {
        Notification::fake();

        $this->register()->assertCreated();
        $user = User::firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, url('/verify-email/'.$user->id.'/'))
                && str_contains($url, 'signature=');
        });
    }

    public function test_registration_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->register([
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'timezone' => 'Mars/Olympus_Mons',
            'locale' => 'xx',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'timezone', 'locale']);

        $this->assertGuest();
    }
}
