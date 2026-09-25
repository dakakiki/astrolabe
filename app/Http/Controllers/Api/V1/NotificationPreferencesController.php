<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Http\Resources\UserResource;
use App\Notifications\TestEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's email notifications (docs/spec/09, Settings →
 * Notifications). Personal, not per workspace: reminders go to the astrologer
 * who runs the appointment, on their own clock.
 */
class NotificationPreferencesController extends Controller
{
    /**
     * Saving plans the next morning email and the unsent appointment reminders
     * again (User::booted).
     */
    public function update(UpdateNotificationPreferencesRequest $request): UserResource
    {
        $user = $request->user();
        $user->forceFill(['notification_preferences' => $request->preferences()->toArray()])->save();

        return UserResource::make($user);
    }

    /** Queues a test email to the person, the same way reminders go. */
    public function test(Request $request): JsonResponse
    {
        $request->user()->notify(new TestEmail(now()->toIso8601ZuluString()));

        return response()->json(['data' => ['queued' => true]], 202);
    }
}
