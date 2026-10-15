<?php

namespace App\Support\Portal\Push;

/**
 * What the browser's push service said: taken, gone for good (404 / 410 — the
 * browser dropped the subscription, so the row goes too), or not taken this
 * time (kept; the next push tries again).
 */
enum PushResult
{
    case Sent;
    case Expired;
    case Failed;
}
