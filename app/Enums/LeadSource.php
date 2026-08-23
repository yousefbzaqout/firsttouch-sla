<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadSource: string
{
    case Meta = 'meta';
    case TikTok = 'tiktok';
    case Google = 'google';
    case Snapchat = 'snapchat';
    case Universal = 'universal';
    case Website = 'website';
    case Manual = 'manual';
}
