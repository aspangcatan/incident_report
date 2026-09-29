<?php

namespace App\DataTransferObjects\Approvals;

/**
 * `comments` maps to the `approvals.decision_comments` column at the
 * Service layer, not a same-named one - that column was deliberately
 * renamed away from a bare "comments" so it wouldn't sit ambiguously next
 * to `request_comments` on the same row. This DTO's own scope (deciding
 * one approval) is narrow enough that "comments" alone isn't ambiguous
 * here.
 */
final class DecideApprovalData
{
    public function __construct(
        public readonly string $comments,
        // The CQI Office may finalize the Department Head's lessons learned when approving.
        public readonly ?string $lessonsLearned = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(comments: $data['comments'], lessonsLearned: $data['lessons_learned'] ?? null);
    }
}
