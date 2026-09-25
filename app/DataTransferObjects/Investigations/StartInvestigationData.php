<?php

namespace App\DataTransferObjects\Investigations;

use App\Enums\InvestigationMethodology;
use Illuminate\Support\Carbon;

/**
 * Validated input for InvestigationService::start(). $teamMembers is
 * left as an array of ['user_id' => int, 'role_in_team' => string]
 * shapes rather than a nested DTO — it's already validated array data
 * by the time it reaches here, and wrapping it doesn't buy type safety
 * the Service doesn't already get from the outer DTO's typed properties.
 */
final class StartInvestigationData
{
    public function __construct(
        public readonly string $objective,
        public readonly InvestigationMethodology $methodology,
        public readonly ?Carbon $targetCompletionAt,
        public readonly array $teamMembers,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            objective: $data['objective'],
            methodology: InvestigationMethodology::from($data['methodology'] ?? InvestigationMethodology::Simple->value),
            targetCompletionAt: isset($data['target_completion_at']) ? Carbon::parse($data['target_completion_at']) : null,
            teamMembers: $data['team_members'] ?? [],
        );
    }
}
