<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Portal\PracticeLogo;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A practice's logo in the portal, through a signed link (PracticeLogo::portalUrl):
 * shown on the invitation page and the practice choice, before any practice
 * is open, so it needs no session.
 */
class LogoController extends Controller
{
    public function __invoke(int $workspace): StreamedResponse
    {
        return PracticeLogo::response(Workspace::query()->findOrFail($workspace));
    }
}
