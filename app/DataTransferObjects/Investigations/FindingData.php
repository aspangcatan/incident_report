<?php

namespace App\DataTransferObjects\Investigations;

final class FindingData
{
    public function __construct(
        public readonly ?string $category,
        public readonly ?string $question,
        public readonly string $finding,
        public readonly bool $isRootCause,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            category: $data['category'] ?? null,
            question: $data['question'] ?? null,
            finding: $data['finding'],
            isRootCause: $data['is_root_cause'] ?? false,
        );
    }

    public function toAttributes(): array
    {
        return [
            'category' => $this->category,
            'question' => $this->question,
            'finding' => $this->finding,
            'is_root_cause' => $this->isRootCause,
        ];
    }
}
