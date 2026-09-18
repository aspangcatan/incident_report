<?php

namespace App\DataTransferObjects\CorrectiveActions;

final class CompleteCorrectiveActionData
{
    public function __construct(
        public readonly string $completionNotes,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(completionNotes: $data['completion_notes']);
    }
}
