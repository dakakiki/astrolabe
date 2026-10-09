<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Legal\AcceptLegalDocuments;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Legal\LegalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Accepting a new version of the Terms or the DPA on the "updated terms"
 * screen, or dismissing the notice about a new privacy policy (Phase 8c).
 * Answers with the person, whose `legal.pending` is then empty.
 */
class LegalAcceptanceController extends Controller
{
    public function store(Request $request, AcceptLegalDocuments $accept): JsonResponse
    {
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['required', 'string', 'max:32'],
            // A document that blocks the practice is accepted on purpose, never by dismissing a notice.
            'accept' => array_intersect(array_keys((array) $request->input('documents')), $this->acceptable()) !== []
                ? ['required', 'accepted']
                : ['nullable'],
        ]);

        if (array_diff(array_keys($data['documents']), LegalDocuments::slugs()) !== []) {
            throw ValidationException::withMessages(['documents' => __('legal.not_acceptable')]);
        }

        $accept->handle($request->user(), $data['documents']);

        return response()->json(['data' => UserResource::make($request->user())->resolve($request)]);
    }

    /**
     * @return list<string>
     */
    private function acceptable(): array
    {
        return array_keys(array_filter(config('astrolabe.legal.documents'), fn (array $settings) => $settings['acceptance'] ?? false));
    }
}
