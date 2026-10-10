<?php

namespace App\Enums;

enum MediaAssetType: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Other = 'other';
}
