<?php

namespace App\Actions\Legal;

use App\Enums\AuditEvent;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Legal\LegalDocuments;
use Illuminate\Validation\ValidationException;

/**
 * Records that a person accepted (Terms, DPA) or saw (privacy policy) the
 * versions they were shown — at registration and on the "updated terms"
 * screen (Phase 8c). The versions come from the page, so a document that
 * changed while the page was open is refused rather than accepted unread.
 */
class AcceptLegalDocuments
{
    /**
     * @param  array<string, string>  $versions  document => version shown
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $versions, string $field = 'documents'): void
    {
        $current = LegalDocuments::currentVersions();

        foreach ($versions as $slug => $version) {
            if (! isset($current[$slug]) || $current[$slug] !== $version) {
                throw ValidationException::withMessages([$field => __('legal.changed')]);
            }
        }

        if ($versions === []) {
            return;
        }

        $request = request();
        $userAgent = $request->userAgent();

        LegalAcceptance::query()->insertOrIgnore(collect($versions)->map(fn (string $version, string $slug) => [
            'user_id' => $user->getKey(),
            'document' => $slug,
            'version' => $version,
            'accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ])->values()->all());

        $user->unsetRelation('legalAcceptances');

        // Document names and versions only — both are ours, nothing about the person.
        Audit::record(AuditEvent::LegalAccepted, $user, ['documents' => $versions], $user);
    }
}
