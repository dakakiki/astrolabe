<?php

namespace Tests\Feature\Portal;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * Portal → Profile, the reminder a day before each appointment (Phase 9b,
 * docs/spec/12): on for a new account, switched off and on by the client,
 * audited by name only, and the same for every practice the account opens.
 */
class PortalProfileRemindersTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private Client $client;

    private PortalUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $astrologer = User::factory()->withWorkspace(['name' => 'Vega Practice'])->create();
        $this->client = Client::factory()->inWorkspace($astrologer->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->user = $this->portalUserFor($this->client);
    }

    public function test_reminders_are_on_until_the_client_switches_them_off(): void
    {
        $this->signInToPortal($this->user);

        $this->portal('GET', 'profile')->assertOk()->assertJsonPath('data.appointment_reminders', true);

        $this->portal('PUT', 'profile', ['appointment_reminders' => false])
            ->assertOk()
            ->assertJsonPath('data.appointment_reminders', false);

        $this->assertFalse($this->user->fresh()->appointment_reminders);
        $this->portal('GET', 'profile')->assertOk()->assertJsonPath('data.appointment_reminders', false);

        $entry = AuditLog::query()
            ->where('event', AuditEvent::PortalProfileUpdated)
            ->where('portal_user_id', $this->user->id)
            ->sole();
        $this->assertSame(['fields' => ['appointment_reminders']], $entry->properties);

        // Saving the rest of the profile without it leaves it as it is.
        $this->portal('PUT', 'profile', ['name' => 'Ana'])
            ->assertOk()
            ->assertJsonPath('data.appointment_reminders', false)
            ->assertJsonPath('data.name', 'Ana');

        $this->portal('PUT', 'profile', ['appointment_reminders' => true])
            ->assertOk()
            ->assertJsonPath('data.appointment_reminders', true);
    }

    public function test_only_a_yes_or_no_is_taken(): void
    {
        $this->signInToPortal($this->user);

        $this->portal('PUT', 'profile', ['appointment_reminders' => 'often'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_reminders');
        $this->portal('PUT', 'profile', ['appointment_reminders' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_reminders');

        $this->assertTrue($this->user->fresh()->appointment_reminders);
    }

    public function test_the_setting_belongs_to_the_account_in_every_practice(): void
    {
        $other = User::factory()->withWorkspace(['name' => 'Luna Studio'])->create();
        $second = Client::factory()->inWorkspace($other->current_workspace_id)->create(['email' => 'ana@example.com']);
        $this->portalUserFor($second, $this->user);

        $practices = collect($this->signInToPortal($this->user)->json('data.practices'));

        $this->portal('PUT', 'practice', ['practice_id' => $practices->firstWhere('name', 'Vega Practice')['id']])->assertOk();
        $this->portal('PUT', 'profile', ['appointment_reminders' => false])->assertOk();

        $this->portal('PUT', 'practice', ['practice_id' => $practices->firstWhere('name', 'Luna Studio')['id']])->assertOk();
        $this->portal('GET', 'profile')->assertOk()->assertJsonPath('data.appointment_reminders', false);
    }
}
