<?php

namespace App\DataTransferObjects\CorrectiveActions;

use App\Enums\CorrectiveActionPriority;
use App\Enums\CorrectiveActionType;
use Illuminate\Support\Carbon;

final class CorrectiveActionData
{
    public function __construct(
        public readonly string $description,
        public readonly CorrectiveActionType $actionType,
        public readonly CorrectiveActionPriority $priority,
        public readonly Carbon $dueDate,
        public readonly ?int $responsibleUserId,
        public readonly ?int $responsibleDepartmentId,
        public readonly ?int $rootCauseFindingId,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'],
            actionType: CorrectiveActionType::from($data['action_type']),
            priority: CorrectiveActionPriority::from($data['priority']),
            dueDate: Carbon::parse($data['due_date']),
            responsibleUserId: isset($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null,
            responsibleDepartmentId: isset($data['responsible_department_id']) ? (int) $data['responsible_department_id'] : null,
            rootCauseFindingId: isset($data['root_cause_finding_id']) ? (int) $data['root_cause_finding_id'] : null,
        );
    }

    public function toAttributes(): array
    {
        return [
            'description' => $this->description,
            'action_type' => $this->actionType,
            'priority' => $this->priority,
            'due_date' => $this->dueDate,
            'responsible_user_id' => $this->responsibleUserId,
            'responsible_department_id' => $this->responsibleDepartmentId,
            'root_cause_finding_id' => $this->rootCauseFindingId,
        ];
    }
}
