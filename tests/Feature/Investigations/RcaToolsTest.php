<?php

namespace Tests\Feature\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\RcaTool;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Investigation;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RcaToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $investigator;

    private Investigation $investigation;

    protected function setUp(): void
    {
        parent::setUp();
        $department = Department::factory()->create();
        $this->investigator = User::factory()->create(['department_id' => $department->id]);
        $service = app(IncidentService::class);
        $incident = $service->createDraft(User::factory()->create(), [
            'department_id' => $department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'severity' => 'level_3_high',
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $service->markReviewed($incident->fresh(), $cqi, null);
        $service->assignInvestigator($incident->fresh(), $this->investigator);
        $this->investigation = app(InvestigationService::class)->start($incident->fresh(), $this->investigator, StartInvestigationData::fromArray(['objective' => 'Find out why.']));
    }

    private function add(array $data)
    {
        return $this->actingAs($this->investigator)->post("/investigations/{$this->investigation->id}/findings", $data);
    }

    public function test_every_tool_can_be_used_in_the_same_investigation(): void
    {
        $this->add(['tool' => 'five_whys', 'question' => 'Why was the dose wrong?', 'finding' => 'Pump set to mL not mg.'])->assertSessionHasNoErrors();
        $this->add(['tool' => 'fishbone', 'group_name' => 'equipment', 'finding' => 'Pump menu is confusing.'])->assertSessionHasNoErrors();
        $this->add(['tool' => 'process_map', 'question' => 'Second nurse checks the pump.', 'finding' => 'No second check.', 'is_flagged' => true])->assertSessionHasNoErrors();
        $this->add(['tool' => 'timeline', 'occurred_at' => '2026-09-28 14:05', 'finding' => 'Infusion started.', 'is_flagged' => true])->assertSessionHasNoErrors();
        $this->add(['tool' => 'barrier', 'question' => 'Independent double check', 'group_name' => 'not_used', 'finding' => 'Short-staffed on night shift.'])->assertSessionHasNoErrors();
        $this->add(['finding' => 'A plain finding.'])->assertSessionHasNoErrors();

        $tools = $this->investigation->findings()->get()->map(fn ($f) => $f->tool->value)->all();
        $this->assertEqualsCanonicalizing(array_map(fn (RcaTool $t) => $t->value, RcaTool::cases()), $tools);
        $this->assertTrue($this->investigation->findings()->where('tool', 'process_map')->first()->is_flagged);
    }

    public function test_each_tool_requires_its_own_fields(): void
    {
        $this->add(['tool' => 'five_whys', 'finding' => 'x'])->assertSessionHasErrors(['question' => 'The why field is required.']);
        $this->add(['tool' => 'fishbone', 'finding' => 'x'])->assertSessionHasErrors('group_name');
        $this->add(['tool' => 'fishbone', 'group_name' => 'luck', 'finding' => 'x'])->assertSessionHasErrors('group_name');
        $this->add(['tool' => 'process_map', 'finding' => 'x'])->assertSessionHasErrors('question');
        $this->add(['tool' => 'timeline', 'finding' => 'x'])->assertSessionHasErrors('occurred_at');
        $this->add(['tool' => 'barrier', 'question' => 'Wristband check', 'finding' => 'x'])->assertSessionHasErrors('group_name');
        $this->add(['tool' => 'magic', 'finding' => 'x'])->assertSessionHasErrors('tool');
    }

    public function test_numbering_is_per_tool_and_stays_contiguous_after_a_delete(): void
    {
        $this->add(['tool' => 'five_whys', 'question' => 'Why 1', 'finding' => 'Because 1']);
        $this->add(['tool' => 'process_map', 'question' => 'Step A', 'finding' => 'Done A']);
        $this->add(['tool' => 'five_whys', 'question' => 'Why 2', 'finding' => 'Because 2']);
        $this->add(['tool' => 'five_whys', 'question' => 'Why 3', 'finding' => 'Because 3']);

        $whys = fn () => $this->investigation->findings()->where('tool', 'five_whys')->get();
        $this->assertSame([1, 2, 3], $whys()->pluck('sequence')->all());
        $this->assertSame(1, $this->investigation->findings()->where('tool', 'process_map')->first()->sequence);

        $second = $whys()->firstWhere('question', 'Why 2');
        $this->actingAs($this->investigator)->delete("/investigations/{$this->investigation->id}/findings/{$second->id}");

        $this->assertSame([1, 2], $whys()->pluck('sequence')->all());
        $this->assertSame(1, $this->investigation->findings()->where('tool', 'process_map')->first()->sequence);
    }

    public function test_a_finding_keeps_its_tool_when_edited(): void
    {
        $this->add(['tool' => 'fishbone', 'group_name' => 'people', 'finding' => 'Fatigue.']);
        $finding = $this->investigation->findings()->first();

        $this->actingAs($this->investigator)
            ->patch("/investigations/{$this->investigation->id}/findings/{$finding->id}", ['tool' => 'simple', 'finding' => 'Fatigue after a double shift.'])
            ->assertSessionHasErrors('group_name');

        $this->actingAs($this->investigator)
            ->patch("/investigations/{$this->investigation->id}/findings/{$finding->id}", ['tool' => 'simple', 'group_name' => 'management', 'finding' => 'Rostering allowed a double shift.', 'is_root_cause' => true, 'category' => 'staffing'])
            ->assertSessionHasNoErrors();

        $finding->refresh();
        $this->assertSame(RcaTool::Fishbone, $finding->tool);
        $this->assertSame('management', $finding->group_name);
        $this->assertTrue($finding->is_root_cause);
    }
}
