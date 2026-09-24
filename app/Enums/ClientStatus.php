<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Lead = 'lead';
    case Active = 'active';
    case Inactive = 'inactive';

    /** Hidden from the default list; restored clients become active again. */
    case Archived = 'archived';
}
