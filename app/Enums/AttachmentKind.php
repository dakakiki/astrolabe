<?php

namespace App\Enums;

enum AttachmentKind: string
{
    /** An uploaded file in private storage. */
    case File = 'file';

    /** A link to something kept elsewhere, such as a recording or a shared document. */
    case Link = 'link';
}
