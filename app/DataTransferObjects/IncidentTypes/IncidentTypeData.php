<?php

namespace App\DataTransferObjects\IncidentTypes;

use App\Enums\Severity;

final class IncidentTypeData
{
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly ?Severity $defaultSeverity,
        public readonly bool $isActive,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            category: $data['category'],
            defaultSeverity: Severity::tryFrom($data['default_severity'] ?? ''),
            isActive: (bool) $data['is_active'],
        );
    }

    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'default_severity' => $this->defaultSeverity,
            'is_active' => $this->isActive,
        ];
    }
}
