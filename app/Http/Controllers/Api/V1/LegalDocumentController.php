<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Legal\LegalDocument;
use App\Support\Legal\LegalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Terms of Service, the Data Processing Agreement and the Privacy Policy
 * (Phase 8c), public: read before registering and linked from the website.
 * An earlier version stays readable (`?version=`), so anyone can see exactly
 * what they accepted.
 */
class LegalDocumentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (LegalDocument $document) => $document->summaryArray(), LegalDocuments::inForce()),
        ]);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $version = $request->query('version');
        $found = is_string($version) && $version !== ''
            ? LegalDocuments::find($document, $version)
            : LegalDocuments::current($document);

        abort_if($found === null, 404);

        $current = LegalDocuments::current($document);

        return response()->json([
            'data' => $found->summaryArray() + $found->render() + [
                'current' => $found->version === $current?->version,
                'versions' => array_map(fn (LegalDocument $version) => [
                    'version' => $version->version,
                    'effective_on' => $version->effectiveOn?->toDateString(),
                    'summary' => $version->summary,
                ], LegalDocuments::versions($document)),
            ],
        ]);
    }
}
