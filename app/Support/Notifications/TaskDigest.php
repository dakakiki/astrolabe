<?php

namespace App\Support\Notifications;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The morning email about tasks (docs/spec/02, "Zadaci i follow-up"): once a
 * day, at the time the person chose on their own clock, how many of their
 * tasks marked "Remind me" are due that day and how many are overdue. Sent
 * only when something is due that day (`notifications:send-digests`).
 */
final class TaskDigest
{
    /** The next morning email after `$now`, in UTC; null when the person does not want it. */
    public static function nextAt(User $user, CarbonInterface $now): ?CarbonImmutable
    {
        $preferences = $user->notificationPreferences();

        if (! $preferences->taskDigest) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $preferences->digestTime));
        $local = CarbonImmutable::instance($now)->setTimezone($user->timezone ?: 'UTC');
        $at = $local->setTime($hours, $minutes);

        if ($at <= $local) {
            $at = $local->startOfDay()->addDay()->setTime($hours, $minutes);
        }

        return $at->utc();
    }

    /**
     * The person's open "Remind me" tasks in the current workspace: due during
     * their day (`today`) and due before it began (`overdue`).
     *
     * @return array{today: int, overdue: int}
     */
    public static function counts(User $user, CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->setTimezone($user->timezone ?: 'UTC')->startOfDay();
        $tomorrow = $today->addDay();
        $tasks = fn () => Task::query()->open()->for($user)->where('remind', true);

        return [
            'today' => $tasks()->where('due_at', '>', $today->utc())->where('due_at', '<=', $tomorrow->utc())->count(),
            'overdue' => $tasks()->where('due_at', '<=', $today->utc())->count(),
        ];
    }
}
