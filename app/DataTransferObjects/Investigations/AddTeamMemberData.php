<?php

namespace App\DataTransferObjects\Investigations;

final class AddTeamMemberData
{
    public function __construct(
        public readonly int $userId,
        public readonly string $roleInTeam,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            userId: (int) $data['user_id'],
            roleInTeam: $data['role_in_team'],
        );
    }
}