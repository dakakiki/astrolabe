<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\MessageText;
use App\Support\Audit\Audit;
use App\Support\Copy\MessageTexts;
use App\Support\Copy\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Admin → Notifications (docs/spec/09, "Obaveštenja — tekstovi"): the operator words the
 * texts of every notification — emails, the client's push messages and the frame of every
 * email — in the app's language. Only the texts a message is made of (NotificationCatalog);
 * a text keeps to the placeholders it is given and keeps the ones it cannot do without
 * (the sign-in code). Saving a text back to the code's wording, or empty where it is not
 * allowed, removes the operator's version. Every save is in the audit log, without the texts.
 */
class AdminNotificationController extends Controller
{
    /** Where a text may be left empty: a line of text is then left out of the email. */
    private const MAY_BE_EMPTY = ['intro', 'outro', 'inline', 'layout'];

    private const MAX = ['subject' => 200, 'title' => 80, 'body' => 200, 'action' => 60];

    public function __construct(private readonly NotificationCatalog $catalog) {}

    /** Every message, by whom it is for, with the subject or title it has now and whether it was worded. */
    public function index(): JsonResponse
    {
        $locale = app()->getLocale();
        $worded = MessageTexts::forLocale($locale);

        $messages = collect($this->catalog->describeAll())->map(function (array $message, string $id) use ($worded) {
            $output = $this->catalog->render($id);

            return [
                'id' => $id,
                'group' => $message['group'],
                'channel' => $message['channel'],
                'label' => self::words($id)['label'],
                'when' => self::words($id)['when'],
                'subject' => $output['subject'] ?? $output['title'] ?? null,
                'body' => $output['body'] ?? null,
                'worded' => count(array_intersect(array_keys($message['texts']), array_keys($worded))),
            ];
        })->values();

        return response()->json(['data' => $messages, 'meta' => ['locale' => $locale]]);
    }

    public function show(string $notification): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->find($notification))]);
    }

    /** The message with unsaved texts, rendered — nothing is stored. */
    public function preview(Request $request, string $notification): JsonResponse
    {
        $message = $this->find($notification);
        $texts = $this->validated($request, $message);

        return response()->json(['data' => $this->rendered($message['id'], $texts)]);
    }

    public function update(Request $request, string $notification): JsonResponse
    {
        $message = $this->find($notification);
        $texts = $this->validated($request, $message);
        $locale = app()->getLocale();
        $changed = [];

        DB::transaction(function () use ($texts, $message, $locale, $request, &$changed) {
            foreach ($texts as $key => $value) {
                $default = $message['texts'][$key]['default'];
                $current = MessageText::query()->where('locale', $locale)->where('key', $key)->first();

                if ($value === $default) {
                    if ($current !== null) {
                        $current->delete();
                        $changed[] = $key;
                    }

                    continue;
                }

                if ($current?->value === $value) {
                    continue;
                }

                MessageText::query()->updateOrCreate(
                    ['locale' => $locale, 'key' => $key],
                    ['value' => $value, 'updated_by' => $request->user()->getKey()],
                );
                $changed[] = $key;
            }
        });

        MessageTexts::forget();

        if ($changed !== []) {
            Audit::record(AuditEvent::AdminNotificationTextsChanged, null, [
                'notification' => $message['id'],
                'keys' => $changed,
                'locale' => $locale,
            ]);
        }

        return response()->json(['data' => $this->payload($this->find($notification))]);
    }

    /**
     * The message's name and when it goes; ids hold dots, so they are read from the list itself.
     *
     * @return array{label: string, when: string}
     */
    private static function words(string $id): array
    {
        $messages = (array) __('admin.notification_texts.messages');

        return $messages[$id] ?? ['label' => $id, 'when' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function find(string $id): array
    {
        try {
            return $this->catalog->describe($id);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    /**
     * The sent texts, checked: only the message's own, within their length, with no
     * placeholder it is not given and none it must keep missing. A text sent as null
     * goes back to the code's wording.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, string>
     */
    private function validated(Request $request, array $message): array
    {
        $request->validate(['texts' => ['required', 'array']]);
        $errors = [];
        $texts = [];

        foreach ((array) $request->input('texts') as $key => $value) {
            $text = $message['texts'][$key] ?? null;
            $field = "texts.{$key}";

            if ($text === null) {
                $errors[$field] = __('admin.notification_texts.errors.unknown');

                continue;
            }

            if ($value === null) {
                $texts[$key] = $text['default'];

                continue;
            }

            if (! is_string($value)) {
                $errors[$field] = __('admin.notification_texts.errors.text');

                continue;
            }

            $value = trim(str_replace("\r\n", "\n", $value));
            $max = self::MAX[$text['role']] ?? 2000;

            if ($value === '' && ! in_array($text['role'], self::MAY_BE_EMPTY, true)) {
                $errors[$field] = __('admin.notification_texts.errors.empty');
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = __('admin.notification_texts.errors.long', ['max' => $max]);
            } elseif ($unknown = array_diff(self::placeholders($value), $text['placeholders'])) {
                $errors[$field] = __('admin.notification_texts.errors.placeholder', ['names' => ':'.implode(', :', $unknown)]);
            } elseif ($missing = array_diff($text['required'], self::placeholders($value))) {
                $errors[$field] = __('admin.notification_texts.errors.required', ['names' => ':'.implode(', :', $missing)]);
            } else {
                $texts[$key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $texts;
    }

    /**
     * Placeholder names in a text: ":name" after a space or the start, not a time like "10:30".
     *
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/(?<![\w:\/]):([A-Za-z][A-Za-z_]*)/', $text, $matches);

        return array_values(array_unique(array_map(fn (string $name) => lcfirst($name) === $name ? $name : strtolower($name), $matches[1])));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function payload(array $message): array
    {
        $worded = MessageTexts::forLocale(app()->getLocale());

        return [
            'id' => $message['id'],
            'group' => $message['group'],
            'channel' => $message['channel'],
            'label' => self::words($message['id'])['label'],
            'when' => self::words($message['id'])['when'],
            'texts' => collect($message['texts'])->map(fn (array $text) => [
                ...$text,
                'value' => $worded[$text['key']] ?? null,
            ])->values(),
            'preview' => $this->rendered($message['id']),
        ];
    }

    /**
     * The message rendered; the layout as a client's and as an astrologer's email.
     *
     * @param  array<string, string>  $texts
     * @return array<string, mixed>
     */
    private function rendered(string $id, array $texts = []): array
    {
        if ($id === 'layout') {
            return [
                'client' => $this->catalog->render('client.confirmed', $texts),
                'astrologer' => $this->catalog->render('astrologer.reminder', $texts),
            ];
        }

        return $this->catalog->render($id, $texts);
    }
}
