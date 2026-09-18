<?php

namespace App\DataTransferObjects\Approvals;

final class DecideApprovalData
{
    public function __construct(
        public readonly string $comments,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(comments: $data['comments']);
    }
}
