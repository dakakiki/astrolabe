<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    /** Created the workspace; manages settings, methods and (later) billing and team. */
    case Owner = 'owner';

    /** Works inside the workspace without managing it. Used once teams arrive (P2). */
    case Member = 'member';
}
