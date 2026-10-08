<?php

namespace App\Enums;

/** Where a practice export is: being built, ready to download, or failed. */
enum ExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
