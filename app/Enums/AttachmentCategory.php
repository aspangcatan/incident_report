<?php

namespace App\Enums;

enum AttachmentCategory: string
{
    case Evidence = 'evidence';
    case Photo = 'photo';
    case Document = 'document';
    case Report = 'report';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Evidence => 'Evidence',
            self::Photo => 'Photo',
            self::Document => 'Document',
            self::Report => 'Report',
            self::Other => 'Other',
        };
    }
}
