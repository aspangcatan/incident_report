<?php

namespace App\DataTransferObjects\Approvals;

/**
 * `justification` maps to the `approvals.request_comments` column at the
 * Service layer - named for what the requester is actually asked to type
 * on this specific form ("why does this incident need no corrective
 * action?"), not for the generic column it's stored in, which stays null
 * on the ordinary CAPA-verified request path.
 */
final class MarkNoCorrectiveActionNeededData
{
    public function __construct(
        public readonly string $justification,
        public readonly ?string $lessonsLearned = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(justification: $data['justification'], lessonsLearned: $data['lessons_learned'] ?? null);
    }
}
