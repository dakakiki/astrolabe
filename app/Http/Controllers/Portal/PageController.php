<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The portal SPA's page, for every path on the portal host except its API.
 */
class PageController extends Controller
{
    public function __invoke(): View
    {
        return view('portal');
    }
}
