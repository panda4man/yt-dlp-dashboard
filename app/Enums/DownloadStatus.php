<?php

namespace App\Enums;

enum DownloadStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Staged     = 'staged';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
