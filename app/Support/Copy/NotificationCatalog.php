<?php

namespace App\Support\Copy;

use App\Models\PortalUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceExport;
use App\Notifications\AccountNotice;
use App\Notifications\AdminWelcome;
use App\Notifications\AppointmentReminder;
use App\Notifications\ClientAppointmentCancelled;
use App\Notifications\ClientAppointmentEmail;
use App\Notifications\ClientAppointmentMoved;
use App\Notifications\ClientAppointmentReminder;
use App\Notifications\ClientBookingConfirmed;
use App\Notifications\ClientBookingDeclined;
use App\Notifications\ClientBookingReceived;
use App\Notifications\ClientMoveRequest;
use App\Notifications\OperatorAlert;
use App\Notifications\PortalBookingNotice;
use App\Notifications\PortalInvite;
use App\Notifications\PortalSignInLink;
use App\Notifications\PracticeDeleted;
use App\Notifications\PracticeDeletionCancelled;
use App\Notifications\PracticeDeletionScheduled;
use App\Notifications\PracticeExportReady;
use App\Notifications\RegistrationInvite;
use App\Notifications\TasksDueToday;
use App\Notifications\TestEmail;
use App\Support\Notifications\ClientAppointmentFacts;
use App\Support\Portal\Push\PushMessage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use InvalidArgumentException;

/**
 * Every notification the operator can word (Admin → Notifications; docs/spec/09): the
 * emails, the client's push messages and the frame around every email (`layout`). Each
 * is made from sample values and rendered once with a recording translator, which tells
 * which texts (translation keys) it is made of, in order, where each one shows (subject,
 * text, button, push title …) and which placeholders it is given. The operator words
 * those keys and nothing else; links, codes and buttons' addresses stay in the code.
 */
final class NotificationCatalog
{
    public const GROUPS = ['astrologer', 'client', 'operator'];

    /** Placeholders a text must keep: without them the notice cannot do its job. */
    private const REQUIRED = ['code'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $described = null;

    /**
     * The messages: [id => [group, channel 'mail'|'push'|'layout', build]] — `build` returns
     * [notifiable, notification] for a mail, a PushMessage maker for a push.
     *
     * @return array<string, array{group: string, channel: string}>
     */
    public function entries(): array
    {
        return collect($this->samples())->map(fn (array $entry) => ['group' => $entry['group'], 'channel' => $entry['channel']])->all();
    }

    /**
     * One message as the admin screen shows it: its texts in order, with the default,
     * the placeholders each may use and those it must keep, and how many messages share it.
     *
     * @return array<string, mixed>
     */
    public function describe(string $id): array
    {
        $all = $this->describeAll();

        return $all[$id] ?? throw new InvalidArgumentException("Unknown notification {$id}.");
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function describeAll(): array
    {
        if ($this->described !== null) {
            return $this->described;
        }

        $described = [];
        $uses = [];

        foreach ($this->samples() as $id => $entry) {
            $texts = $this->record($entry);
            $described[$id] = ['id' => $id, 'group' => $entry['group'], 'channel' => $entry['channel'], 'texts' => $texts];

            foreach (array_keys($texts) as $key) {
                $uses[$key][] = $id;
            }
        }

        foreach ($described as $id => $message) {
            foreach ($message['texts'] as $key => $text) {
                $described[$id]['texts'][$key]['shared'] = count(array_unique($uses[$key])) - 1;
            }
        }

        return $this->described = $described;
    }

    /**
     * Every key the operator may word, with what it allows (the union over all messages).
     *
     * @return array<string, array{placeholders: list<string>, required: list<string>, role: string}>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->describeAll() as $message) {
            foreach ($message['texts'] as $key => $text) {
                $keys[$key] = [
                    'placeholders' => array_values(array_unique([...($keys[$key]['placeholders'] ?? []), ...$text['placeholders']])),
                    'required' => $text['required'],
                    'role' => $keys[$key]['role'] ?? $text['role'],
                ];
            }
        }

        return $keys;
    }

    /**
     * The message rendered as it would be sent now, optionally with unsaved texts over the saved ones.
     *
     * @param  array<string, string>  $texts
     * @return array{subject?: string, html?: string, title?: string, body?: string}
     */
    public function render(string $id, array $texts = []): array
    {
        $entry = $this->samples()[$id] ?? throw new InvalidArgumentException("Unknown notification {$id}.");

        return MessageTexts::preview($texts, fn () => self::frozen(fn () => $this->output($entry)));
    }

    /**
     * Runs `$run` at the samples' moment, then puts the clock back as it was.
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private static function frozen(callable $run): mixed
    {
        $mutable = Carbon::getTestNow();
        $immutable = CarbonImmutable::getTestNow();
        Carbon::setTestNow(self::now());
        CarbonImmutable::setTestNow(self::now());

        try {
            return $run();
        } finally {
            Carbon::setTestNow($mutable);
            CarbonImmutable::setTestNow($immutable);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{subject?: string, html?: string, title?: string, body?: string}
     */
    private function output(array $entry): array
    {
        if ($entry['channel'] === 'push') {
            $push = ($entry['build'])();

            return ['title' => $push->title, 'body' => $push->body];
        }

        [$notifiable, $notification] = ($entry['build'])();
        $mail = $notification->toMail($notifiable);

        return ['subject' => (string) $mail->subject, 'html' => (string) $mail->render()];
    }

    /**
     * Renders the entry while recording the keys asked for.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, array<string, mixed>>
     */
    private function record(array $entry): array
    {
        /** @var OverridableTranslator $translator */
        $translator = app('translator');
        $seen = [];

        $layout = $entry['group'] === 'layout';
        $result = [];

        // The frame is the same around every email: a client's, an astrologer's and an operator's show all of it.
        foreach ($entry['builds'] ?? [$entry['build']] as $build) {
            $result = array_merge_recursive($result, $translator->recording(function (string $key, array $replace, string $line) use (&$seen, $layout) {
                if (! self::wordable($key) || $layout !== str_starts_with($key, 'mail_layout.')) {
                    return;
                }

                $seen[$key]['placeholders'] = array_values(array_unique([...($seen[$key]['placeholders'] ?? []), ...array_map('strval', array_keys($replace))]));
                $seen[$key]['lines'][] = $line;
            }, fn () => self::frozen(fn () => $this->parts([...$entry, 'build' => $build]))));
        }

        $texts = [];

        foreach ($seen as $key => $found) {
            $default = $translator->original($key);

            $texts[$key] = [
                'key' => $key,
                'default' => $default,
                'placeholders' => $found['placeholders'],
                'required' => array_values(array_filter(self::REQUIRED, fn (string $name) => str_contains($default, ':'.$name))),
                'role' => $layout ? 'layout' : self::role($found['lines'], $result),
            ];
        }

        return $texts;
    }

    /**
     * The parts the rendered message has, to tell where each text shows.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function parts(array $entry): array
    {
        if ($entry['channel'] === 'push') {
            $push = ($entry['build'])();

            return ['title' => $push->title, 'body' => $push->body];
        }

        [$notifiable, $notification] = ($entry['build'])();
        /** @var MailMessage $mail */
        $mail = $notification->toMail($notifiable);
        $mail->render();

        return [
            'subject' => (string) $mail->subject,
            'greeting' => (string) $mail->greeting,
            'intro' => array_map('strval', $mail->introLines),
            'action' => (string) $mail->actionText,
            'outro' => array_map('strval', $mail->outroLines),
        ];
    }

    /**
     * Where a text shows: subject, greeting, text before or after the button, the button,
     * a push title or body, part of another text, or the frame around the email.
     *
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $parts
     */
    private static function role(array $lines, array $parts): string
    {
        foreach ($lines as $line) {
            foreach (['subject', 'greeting', 'action', 'title', 'body'] as $part) {
                if (isset($parts[$part]) && $parts[$part] !== '' && $parts[$part] === $line) {
                    return $part;
                }
            }

            foreach (['intro', 'outro'] as $part) {
                if (in_array($line, $parts[$part] ?? [], true)) {
                    return $part;
                }
            }
        }

        return 'inline';
    }

    /** The texts of notifications and of the email's frame; nothing else the app says. */
    public static function wordable(string $key): bool
    {
        foreach (['notifications.', 'portal.mail.', 'portal.push.', 'mail_layout.', 'operations.', 'admin.feedback.'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        // Laravel's own texts in the address and password emails.
        return in_array($key, [
            'Verify your email address', 'Please click the button below to verify your email address.', 'Verify Email Address',
            'If you did not create an account, no further action is required.',
            'Reset your password', 'You are receiving this email because we received a password reset request for your account.',
            'Reset Password', 'This password reset link will expire in :count minutes.', 'If you did not request a password reset, no further action is required.',
        ], true);
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function samples(): array
    {
        $astro = (new User)->forceFill(['id' => 11, 'name' => 'Vera Vega', 'email' => 'vera@example.com', 'timezone' => 'Europe/Belgrade']);
        $client = (new PortalUser)->forceFill(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com', 'timezone' => 'Europe/Belgrade']);
        $someone = fn () => (new AnonymousNotifiable)->route('mail', 'someone@example.com');
        $now = self::now();

        // No practice row behind the samples: the header shows the practice's name.
        $facts = fn (array $over = []) => new ClientAppointmentFacts(...array_merge([
            'appointmentId' => 42, 'workspaceId' => 0, 'clientId' => 1,
            'practice' => 'Vega Astrology', 'practiceZone' => 'Europe/Belgrade',
            'service' => 'Natal reading', 'location' => 'online', 'status' => 'scheduled',
            'startsAt' => '2026-11-24T14:00:00Z', 'endsAt' => '2026-11-24T15:00:00Z',
            'rescheduleUntil' => '2026-11-23T14:00:00Z', 'cancelUntil' => '2026-11-23T14:00:00Z',
        ], $over));
        $later = ['startsAt' => '2026-11-25T09:00:00Z', 'endsAt' => '2026-11-25T10:00:00Z', 'rescheduleUntil' => '2026-11-24T09:00:00Z', 'cancelUntil' => '2026-11-24T09:00:00Z'];

        $mail = fn (string $group, callable $build) => ['group' => $group, 'channel' => 'mail', 'build' => $build];
        $export = function () use ($now) {
            $export = (new WorkspaceExport)->forceFill(['id' => 5, 'workspace_id' => null, 'expires_at' => $now->addDays(7)]);
            $export->setRelation('workspace', (new Workspace)->forceFill(['name' => 'Vega Astrology']));

            return $export;
        };
        $notice = fn (string $kind, ?string $from = null) => new PortalBookingNotice(
            $kind, 42, 'Natal reading',
            $from ? '2026-11-25T09:00:00Z' : '2026-11-24T14:00:00Z',
            $from ? '2026-11-25T10:00:00Z' : '2026-11-24T15:00:00Z',
            $from, $from ? '2026-11-24T15:00:00Z' : null,
        );

        $mails = [
            'astrologer.reminder' => $mail('astrologer', fn () => [$astro, new AppointmentReminder(42, '2026-11-19T14:00:00Z', '2026-11-19T15:00:00Z')]),
            'astrologer.tasks' => $mail('astrologer', fn () => [$astro, new TasksDueToday('Vega Astrology', 3, 1)]),
            'astrologer.portal_booked' => $mail('astrologer', fn () => [$astro, $notice(PortalBookingNotice::BOOKED)]),
            'astrologer.portal_requested' => $mail('astrologer', fn () => [$astro, $notice(PortalBookingNotice::REQUESTED)]),
            'astrologer.portal_moved' => $mail('astrologer', fn () => [$astro, $notice(PortalBookingNotice::MOVED, '2026-11-24T14:00:00Z')]),
            'astrologer.portal_move_requested' => $mail('astrologer', fn () => [$astro, $notice(PortalBookingNotice::MOVE_REQUESTED, '2026-11-24T14:00:00Z')]),
            'astrologer.portal_cancelled' => $mail('astrologer', fn () => [$astro, $notice(PortalBookingNotice::CANCELLED)]),
            'astrologer.export_ready' => $mail('astrologer', fn () => [$astro, new PracticeExportReady($export())]),
            'astrologer.deletion_scheduled' => $mail('astrologer', fn () => [$astro, new PracticeDeletionScheduled('Vega Astrology', $now->addDays(30)->toIso8601ZuluString())]),
            'astrologer.deletion_cancelled' => $mail('astrologer', fn () => [$astro, new PracticeDeletionCancelled('Vega Astrology')]),
            'astrologer.practice_deleted' => $mail('astrologer', fn () => [$someone(), new PracticeDeleted('Vega Astrology', true)]),
            'astrologer.practice_left' => $mail('astrologer', fn () => [$someone(), new PracticeDeleted('Vega Astrology', false)]),
            'astrologer.two_factor_reset' => $mail('astrologer', fn () => [$astro, new AccountNotice(AccountNotice::TWO_FACTOR_RESET, 'You asked us by email.')]),
            'astrologer.suspended' => $mail('astrologer', fn () => [$astro, new AccountNotice(AccountNotice::SUSPENDED, 'Unpaid invoice.')]),
            'astrologer.restored' => $mail('astrologer', fn () => [$astro, new AccountNotice(AccountNotice::RESTORED, null)]),
            'astrologer.verify_email' => $mail('astrologer', fn () => [$astro, new VerifyEmail]),
            'astrologer.reset_password' => $mail('astrologer', fn () => [$astro, new ResetPassword('sample-token')]),
            'astrologer.invitation' => $mail('astrologer', fn () => [$someone(), new RegistrationInvite('sample-token', $now->addDays(14))]),
            'astrologer.test_email' => $mail('astrologer', fn () => [$astro, new TestEmail($now->toIso8601ZuluString())]),

            'client.invitation' => $mail('client', fn () => [$someone(), new PortalInvite('Vega Astrology', 'Vera Vega', 'sample-token', $now->addDays(7))]),
            'client.sign_in' => $mail('client', fn () => [$someone(), new PortalSignInLink('sample-token', '482913')]),
            'client.booked' => $mail('client', fn () => [$client, new ClientBookingReceived($facts())]),
            'client.requested' => $mail('client', fn () => [$client, new ClientBookingReceived($facts(['status' => 'requested']))]),
            'client.confirmed' => $mail('client', fn () => [$client, new ClientBookingConfirmed($facts())]),
            'client.declined' => $mail('client', fn () => [$client, new ClientBookingDeclined($facts(['status' => 'cancelled']), 'That week is fully booked.')]),
            'client.moved_by_you' => $mail('client', fn () => [$client, new ClientAppointmentMoved($facts($later), '2026-11-24T14:00:00Z', '2026-11-24T15:00:00Z', true)]),
            'client.moved' => $mail('client', fn () => [$client, new ClientAppointmentMoved($facts($later), '2026-11-24T14:00:00Z', '2026-11-24T15:00:00Z', false)]),
            'client.move_requested' => $mail('client', fn () => [$client, new ClientMoveRequest($facts(), ClientMoveRequest::REQUESTED, '2026-11-25T09:00:00Z', '2026-11-25T10:00:00Z')]),
            'client.move_confirmed' => $mail('client', fn () => [$client, new ClientMoveRequest($facts($later), ClientMoveRequest::CONFIRMED, '2026-11-24T14:00:00Z', '2026-11-24T15:00:00Z')]),
            'client.move_declined' => $mail('client', fn () => [$client, new ClientMoveRequest($facts(), ClientMoveRequest::DECLINED, '2026-11-25T09:00:00Z', '2026-11-25T10:00:00Z', 'Wednesday is taken.')]),
            'client.cancelled_by_you' => $mail('client', fn () => [$client, new ClientAppointmentCancelled($facts(['status' => 'cancelled']), true)]),
            'client.cancelled' => $mail('client', fn () => [$client, new ClientAppointmentCancelled($facts(['status' => 'cancelled']), false)]),
            'client.reminder' => $mail('client', fn () => [$client, new ClientAppointmentReminder($facts(['startsAt' => '2026-11-19T14:00:00Z', 'endsAt' => '2026-11-19T15:00:00Z', 'rescheduleUntil' => null, 'cancelUntil' => null]))]),

            'operator.server_error' => $mail('operator', fn () => [$someone(), new OperatorAlert(
                __('operations.exception.subject', ['app' => config('app.name'), 'type' => 'QueryException']),
                [
                    __('operations.exception.type', ['type' => 'Illuminate\\Database\\QueryException']),
                    __('operations.exception.where', ['where' => 'app/Actions/Booking/BookAppointment.php:57']),
                    __('operations.exception.request', ['method' => 'POST', 'path' => '/api/portal/v1/booking']),
                    __('operations.when', ['time' => $now->toDateTimeString()]),
                    __('operations.exception.log'),
                ],
            )]),
            'operator.health_failing' => $mail('operator', fn () => [$someone(), new OperatorAlert(
                __('operations.health.subject', ['app' => config('app.name')]),
                [__('operations.health.failing', ['checks' => 'queue, scheduler']), __('operations.when', ['time' => $now->toDateTimeString()]), __('operations.health.log')],
            )]),
            'operator.health_recovered' => $mail('operator', fn () => [$someone(), new OperatorAlert(
                __('operations.recovered.subject', ['app' => config('app.name')]),
                [__('operations.recovered.line'), __('operations.when', ['time' => $now->toDateTimeString()])],
            )]),
            'operator.feedback' => $mail('operator', fn () => [$someone(), new OperatorAlert(
                __('admin.feedback.subject', ['app' => config('app.name')]),
                [__('admin.feedback.line', ['category' => 'idea', 'user' => 11]), __('operations.when', ['time' => $now->toDateTimeString()])],
            )]),
            'operator.admin_welcome' => $mail('operator', fn () => [$astro, new AdminWelcome(url('/reset-password/sample-token'))]),
        ];

        // The client's push messages: the same notices, as their devices get them.
        $pushes = [];

        foreach ($mails as $id => $entry) {
            if ($entry['group'] !== 'client') {
                continue;
            }

            $pushes['push.'.substr($id, strlen('client.'))] = ['group' => 'client', 'channel' => 'push', 'build' => function () use ($entry, $client) {
                [, $notification] = ($entry['build'])();

                return $notification instanceof ClientAppointmentEmail ? $notification->toPortalPush($client) : null;
            }];
        }

        $pushes = array_filter($pushes, fn (array $entry) => ($entry['build'])() instanceof PushMessage);
        $pushes['push.test'] = ['group' => 'client', 'channel' => 'push', 'build' => fn () => new PushMessage(
            __('portal.push.test.title'), __('portal.push.test.body'), '/profile', 'test',
        )];

        // The frame of every email, shown around a client's, an astrologer's and an operator's email.
        $layout = ['layout' => [
            'group' => 'layout',
            'channel' => 'mail',
            'build' => $mails['client.confirmed']['build'],
            'builds' => [$mails['client.confirmed']['build'], $mails['astrologer.reminder']['build'], $mails['operator.server_error']['build']],
        ]];

        return [...$mails, ...$pushes, ...$layout];
    }
}
