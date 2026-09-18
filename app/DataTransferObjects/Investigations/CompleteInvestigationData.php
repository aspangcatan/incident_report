<?php

namespace App\DataTransferObjects\Investigations;

final class CompleteInvestigationData
{
    public function __construct(
        public readonly string $conclusion,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(conclusion: $data['conclusion']);
    }
}
