<?php

namespace App\DataTransferObjects\Investigations;

final class FindingData
{
    public function __construct(
        public readonly ?string $category,
        public readonly ?string $question,
        public readonly string $finding,
        public readonly bool $isRootCause,
        // RCA tool (App\Enums\RcaTool value); null lets the service decide.
        public readonly ?string $tool = null,
        public readonly ?string $groupName = null,
        public readonly ?string $occurredAt = null,
        public readonly bool $isFlagged = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            category: $data['category'] ?? null,
            question: $data['question'] ?? null,
            finding: $data['finding'],
            isRootCause: $data['is_root_cause'] ?? false,
            tool: $data['tool'] ?? null,
            groupName: $data['group_name'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
            isFlagged: $data['is_flagged'] ?? false,
        );
    }

    /** Everything except the tool, which is fixed once a finding exists. */
    public function toAttributes(): array
    {
        return [
            'category' => $this->category,
            'question' => $this->question,
            'finding' => $this->finding,
            'is_root_cause' => $this->isRootCause,
            'group_name' => $this->groupName,
            'occurred_at' => $this->occurredAt,
            'is_flagged' => $this->isFlagged,
        ];
    }
}
