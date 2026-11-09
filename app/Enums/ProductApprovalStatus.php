<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductApprovalStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Waiting for review',
            self::Approved => 'Approved',
            self::Rejected => 'Not approved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
