<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\MessageText;
use App\Models\PortalUser;
use App\Models\User;
use App\Notifications\ClientBookingConfirmed;
use App\Notifications\PortalSignInLink;
use App\Support\Copy\MessageTexts;
use App\Support\Notifications\ClientAppointmentFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Admin → Notifications (docs/spec/09, "Obaveštenja — tekstovi"; the user's request of
 * 19 Nov 2026): the operator words every notification's texts and the frame of every
 * email; the words reach the email and the push at once, the placeholders a text needs
 * stay, and only the operator may do it.
 */
class AdminNotificationTextsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        MessageTexts::forget();
    }

    private function facts(): ClientAppointmentFacts
    {
        return new ClientAppointmentFacts(
            appointmentId: 1, workspaceId: 0, clientId: 1, practice: 'Vega Astrology', practiceZone: 'Europe/Belgrade',
            service: 'Natal reading', location: 'online', status: 'scheduled',
            startsAt: now()->addDays(3)->toIso8601ZuluString(), endsAt: now()->addDays(3)->addHour()->toIso8601ZuluString(),
        );
    }

    private function client(): PortalUser
    {
        return (new PortalUser)->forceFill(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com', 'timezone' => 'Europe/Belgrade']);
    }

    public function test_only_the_operator_sees_the_notifications(): void
    {
        $astrologer = User::factory()->withWorkspace()->create();
        $this->actingAs($astrologer)->getJson('/api/v1/admin/notifications')->assertForbidden();

        $list = $this->actingAs($this->admin)->getJson('/api/v1/admin/notifications')->assertOk();
        $ids = array_column($list->json('data'), 'id');

        $this->assertContains('client.confirmed', $ids);
        $this->assertContains('push.confirmed', $ids);
        $this->assertContains('layout', $ids);
        $this->assertSame(['astrologer', 'client', 'layout', 'operator'], collect($list->json('data'))->pluck('group')->unique()->sort()->values()->all());
        $confirmed = collect($list->json('data'))->firstWhere('id', 'client.confirmed');
        $this->assertSame('Booking confirmed', $confirmed['label']);
        $this->assertStringStartsWith('Confirmed: your appointment on', $confirmed['subject']);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/notifications/nothing.here')->assertNotFound();
    }

    public function test_a_worded_text_reaches_the_email_and_goes_back_to_the_codes_on_reset(): void
    {
        $show = $this->actingAs($this->admin)->getJson('/api/v1/admin/notifications/client.confirmed')->assertOk();
        $subject = collect($show->json('data.texts'))->firstWhere('role', 'subject');
        $this->assertSame('portal.mail.booking.confirmed.subject', $subject['key']);
        $this->assertSame(['when'], $subject['placeholders']);
        $this->assertNull($subject['value']);

        $this->actingAs($this->admin)->putJson('/api/v1/admin/notifications/client.confirmed', ['texts' => [
            'portal.mail.booking.confirmed.subject' => 'Your appointment on :when is confirmed',
            'portal.mail.booking.private' => '',
        ]])->assertOk()
            ->assertJsonPath('data.preview.subject', fn (string $subject) => str_starts_with($subject, 'Your appointment on '));

        $mail = (new ClientBookingConfirmed($this->facts()))->toMail($this->client());
        $this->assertStringStartsWith('Your appointment on ', $mail->subject);
        $this->assertStringEndsWith(' is confirmed', $mail->subject);
        // A line worded empty is left out of the email.
        $this->assertStringNotContainsString('Everything else about it is in your portal', (string) $mail->render());

        $audit = AuditLog::query()->where('event', AuditEvent::AdminNotificationTextsChanged)->sole();
        $this->assertSame('client.confirmed', $audit->properties['notification']);
        $this->assertEqualsCanonicalizing(['portal.mail.booking.confirmed.subject', 'portal.mail.booking.private'], $audit->properties['keys']);
        $this->assertStringNotContainsString('is confirmed', json_encode($audit->properties));

        // Back to the code's words: the row goes.
        $this->actingAs($this->admin)->putJson('/api/v1/admin/notifications/client.confirmed', ['texts' => [
            'portal.mail.booking.confirmed.subject' => null,
        ]])->assertOk();
        $this->assertFalse(MessageText::query()->where('key', 'portal.mail.booking.confirmed.subject')->exists());
        $this->assertStringStartsWith('Confirmed: your appointment on', (new ClientBookingConfirmed($this->facts()))->toMail($this->client())->subject);
    }

    public function test_a_text_keeps_its_placeholders_and_its_place(): void
    {
        $put = fn (array $texts) => $this->actingAs($this->admin)->putJson('/api/v1/admin/notifications/client.sign_in', ['texts' => $texts]);

        // The code cannot be left out, and no placeholder the email is not given can come in.
        $put(['portal.mail.sign_in.code' => 'Your code is below.'])->assertUnprocessable()->assertJsonValidationErrors('texts.portal.mail.sign_in.code');
        $put(['portal.mail.sign_in.code' => 'Your code: :code, for :practice'])->assertUnprocessable()->assertJsonValidationErrors('texts.portal.mail.sign_in.code');
        $put(['portal.mail.sign_in.subject' => ''])->assertUnprocessable()->assertJsonValidationErrors('texts.portal.mail.sign_in.subject');
        $put(['portal.mail.booking.confirmed.subject' => 'Not here'])->assertUnprocessable()->assertJsonValidationErrors('texts.portal.mail.booking.confirmed.subject');
        $this->assertSame(0, MessageText::query()->count());

        // A time is not a placeholder.
        $put(['portal.mail.sign_in.code' => 'Your code: :code (valid until 10:30 at the latest)'])->assertOk();
        $mail = (new PortalSignInLink('token', '482913'))->toMail($this->client());
        $this->assertStringContainsString('Your code: 482913 (valid until 10:30 at the latest)', implode(' ', $mail->outroLines));
    }

    public function test_the_preview_stores_nothing_and_the_layout_frames_every_email(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/notifications/layout/preview', ['texts' => [
            'mail_layout.footer' => 'AstroLabe, Main Street 1, Belgrade',
            'mail_layout.footer_client' => ':practice — through :app',
        ]])->assertOk()
            ->assertJsonPath('data.client.html', fn (string $html) => str_contains($html, 'Vega Astrology — through AstroLabe'))
            ->assertJsonPath('data.astrologer.html', fn (string $html) => str_contains($html, 'Main Street 1') && ! str_contains($html, 'through AstroLabe'));
        $this->assertSame(0, MessageText::query()->count());

        $this->actingAs($this->admin)->putJson('/api/v1/admin/notifications/layout', ['texts' => [
            'mail_layout.footer' => 'AstroLabe, Main Street 1, Belgrade',
        ]])->assertOk();

        $html = (string) (new ClientBookingConfirmed($this->facts()))->toMail($this->client())->render();
        $this->assertStringContainsString('Main Street 1', $html);
        // A client's email is the practice's: its name in the header and the signature.
        $this->assertStringContainsString('Vega Astrology uses AstroLabe', $html);
        $this->assertMatchesRegularExpression('/Regards,\s*<br\s*\/?>\s*Vega Astrology/', $html);
    }

    public function test_a_push_text_is_worded_too_and_a_worker_reads_new_words_for_each_job(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/admin/notifications/push.confirmed', ['texts' => [
            'portal.push.confirmed.title' => 'Confirmed!',
        ]])->assertOk()->assertJsonPath('data.preview.title', 'Confirmed!');

        $this->assertSame('Confirmed!', (new ClientBookingConfirmed($this->facts()))->toPortalPush($this->client())->title);

        // A long-running worker kept the old words in memory; the next job reads them again.
        MessageText::query()->where('key', 'portal.push.confirmed.title')->update(['value' => 'Confirmed again']);
        $this->assertSame('Confirmed!', __('portal.push.confirmed.title'));
        Event::dispatch(new JobProcessing('sync', new SyncJob(app(), '{}', 'sync', 'default')));
        $this->assertSame('Confirmed again', __('portal.push.confirmed.title'));

        $this->actingAs($this->admin)->putJson('/api/v1/admin/notifications/push.confirmed', ['texts' => [
            'portal.push.confirmed.title' => str_repeat('x', 81),
        ]])->assertUnprocessable();
    }
}
