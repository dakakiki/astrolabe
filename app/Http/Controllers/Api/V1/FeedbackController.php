<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\FeedbackCategory;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Support\Audit\Audit;
use App\Support\Operations\AppVersion;
use App\Support\Operations\OperatorAlerts;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The app's "Feedback" button (Phase 8c): a category, a message and the screen
 * it was sent from, into the admin's inbox. The operator gets an email that
 * something came in — without the message, which may mention a client.
 */
class FeedbackController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $current): Response
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'page' => ['nullable', 'string', 'max:2000'],
        ]);

        $userAgent = $request->userAgent();

        $feedback = Feedback::query()->create([
            'user_id' => $request->user()->getKey(),
            'workspace_id' => $current->id(),
            'category' => $data['category'],
            'message' => trim($data['message']),
            'page' => Feedback::pagePattern($data['page'] ?? null),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'app_version' => AppVersion::current(),
        ]);

        Audit::record(AuditEvent::FeedbackSent, $feedback, ['category' => $feedback->category->value]);
        OperatorAlerts::feedback($feedback);

        return response()->noContent(Response::HTTP_CREATED);
    }
}
