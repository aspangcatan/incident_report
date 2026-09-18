<?php

namespace App\DataTransferObjects\Approvals;

final class MarkNoCorrectiveActionNeededData
{
    public function __construct(
        public readonly string $justification,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(justification: $data['justification']);
    }
}
