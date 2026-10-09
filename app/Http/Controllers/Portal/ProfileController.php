<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ActivityType;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\PortalUser;
use App\Support\Activity\ActivityLog;
use App\Support\Audit\Audit;
use App\Support\Portal\PortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Portal → Profile (docs/spec/12; docs/spec/09: "ažurira ograničene kontakt i
 * regionalne podatke"). Name, time zone and language belong to the portal
 * account; the phone number is the client record's in the open practice, so
 * the astrologer has it — the change shows on the client's timeline as the
 * client's own. The email address is the practice's to change.
 */
class ProfileController extends Controller
{
    public function show(PortalContext $context): JsonResponse
    {
        return response()->json(['data' => $this->profile($context)]);
    }

    public function update(Request $request, PortalContext $context, ActivityLog $activity): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'locale' => ['nullable', Rule::in(array_keys(config('astrolabe.locales')))],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $user->fill(collect($data)->only(['name', 'timezone', 'locale'])->all())->save();
        $fields = array_keys($user->getChanges());

        $client = $context->client();

        if (array_key_exists('phone', $data) && $client->phone !== $data['phone']) {
            $client->forceFill(['phone' => $data['phone']])->save();
            $activity->record($client, ActivityType::ClientUpdated, ['fields' => ['phone'], 'source' => 'portal']);
            $fields[] = 'phone';
        }

        $fields = array_values(array_diff($fields, ['updated_at']));

        if ($fields !== []) {
            Audit::portal(AuditEvent::PortalProfileUpdated, $user, $context->access(), ['fields' => $fields], $context->workspace());
        }

        return response()->json(['data' => $this->profile($context)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(PortalContext $context): array
    {
        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $client = $context->client();

        return [
            'email' => $user->email,
            'name' => $user->name,
            'timezone' => $user->timezone,
            'locale' => $user->locale,
            'phone' => $client->phone,
            'country_code' => $client->country_code,
            'practice_timezone' => $context->workspace()->timezone,
            'locales' => collect(config('astrolabe.locales'))
                ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
                ->values(),
            'countries' => Country::query()
                ->whereNotNull('phone_code')
                ->orderBy('code')
                ->get(['code', 'phone_code'])
                ->map(fn (Country $country) => ['code' => $country->code, 'phone_code' => $country->phone_code]),
        ];
    }
}
