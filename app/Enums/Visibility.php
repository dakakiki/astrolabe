<?php

namespace App\Enums;

/**
 * Who may see a note or a file (docs/spec/02, "Fajlovi i dokumenti").
 */
enum Visibility: string
{
    /** Only the person who wrote or uploaded it. */
    case Private = 'private';

    /** Every active member of the workspace. */
    case Team = 'team';

    /** Members, and later the client through the portal (docs/spec/09). */
    case SharedWithClient = 'shared_with_client';
}
