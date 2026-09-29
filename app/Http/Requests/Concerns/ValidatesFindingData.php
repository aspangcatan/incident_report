<?php

namespace App\Http\Requests\Concerns;

use App\Enums\BarrierStatus;
use App\Enums\FishboneCategory;
use App\Enums\RcaTool;
use App\Enums\RootCauseType;
use Illuminate\Validation\Rules\Enum;

/** Finding rules, which depend on the RCA tool the finding belongs to. */
trait ValidatesFindingData
{
    protected function findingRules(?RcaTool $tool): array
    {
        $rules = [
            'category' => ['nullable', 'required_if:is_root_cause,true', new Enum(RootCauseType::class)],
            'question' => ['nullable', 'string'],
            'finding' => ['required', 'string'],
            'is_root_cause' => ['boolean'],
            'group_name' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
            'is_flagged' => ['boolean'],
        ];

        return match ($tool) {
            RcaTool::FiveWhys, RcaTool::ProcessMap => [...$rules, 'question' => ['required', 'string']],
            RcaTool::Fishbone => [...$rules, 'group_name' => ['required', new Enum(FishboneCategory::class)]],
            RcaTool::Timeline => [...$rules, 'occurred_at' => ['required', 'date']],
            RcaTool::Barrier => [...$rules, 'question' => ['required', 'string'], 'group_name' => ['required', new Enum(BarrierStatus::class)]],
            default => $rules,
        };
    }

    /** Field names as the investigator sees them on that tool's form. */
    protected function findingAttributes(?RcaTool $tool): array
    {
        return match ($tool) {
            RcaTool::FiveWhys => ['question' => 'why', 'finding' => 'because'],
            RcaTool::Fishbone => ['group_name' => 'category', 'finding' => 'cause'],
            RcaTool::ProcessMap => ['question' => 'what should happen', 'finding' => 'what actually happened'],
            RcaTool::Timeline => ['occurred_at' => 'date and time', 'finding' => 'what happened'],
            RcaTool::Barrier => ['question' => 'barrier', 'group_name' => 'status', 'finding' => 'why'],
            default => [],
        } + ['category' => 'type of cause'];
    }
}
