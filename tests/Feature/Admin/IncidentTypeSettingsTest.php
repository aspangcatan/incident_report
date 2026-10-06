<?php

namespace Tests\Feature\Admin;

use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecurrenceReview;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Administrator]);
    }

    /** A draft incident that uses $type through the incident_incident_type pivot. */
    private function incidentUsing(IncidentType $type): Incident
    {
        return app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_ids' => [$type->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
    }

    private function recurrenceReviewUsing(IncidentType $type): RecurrenceReview
    {
        $user = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        return RecurrenceReview::create([
            'department_id' => Department::factory()->create()->id,
            'incident_type_id' => $type->id,
            'incident_count' => 3,
            'status' => RecurrenceReviewStatus::Open,
            'assigned_to' => $user->id,
            'due_date' => now()->addDays(14),
            'cqi_notes' => 'Pattern.',
            'created_by' => $user->id,
        ]);
    }

    public function test_a_new_type_is_not_in_use(): void
    {
        $this->assertFalse(IncidentType::factory()->create()->isInUse());
    }

    public function test_a_type_on_an_incident_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $this->incidentUsing($type);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_a_type_on_the_legacy_incident_column_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing(IncidentType::factory()->create());
        DB::table('incidents')->where('id', $incident->id)->update(['incident_type_id' => $type->id]);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_a_type_on_a_recurrence_review_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $this->recurrenceReviewUsing($type);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_only_the_it_admin_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/incident-types')->assertOk();

        foreach ([Role::QualitySafetyOfficer, Role::DepartmentHead, Role::Staff, Role::Management] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/incident-types')->assertForbidden();
        }
    }

    public function test_the_page_lists_types_with_usage_and_options(): void
    {
        $used = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury', 'default_severity' => Severity::Level3High]);
        $this->incidentUsing($used);
        IncidentType::factory()->create(['name' => 'Spills', 'category' => 'environment', 'is_active' => false]);

        $this->actingAs($this->admin())->get('/admin/incident-types')
            ->assertInertia(fn ($page) => $page->component('Admin/IncidentTypes')
                ->has('types', 2)
                ->where('types.0.name', 'Falls')
                ->where('types.0.category_label', 'Injury')
                ->where('types.0.default_severity', 'level_3_high')
                ->where('types.0.usage_count', 1)
                ->where('types.0.can_delete', false)
                ->where('types.1.name', 'Spills')
                ->where('types.1.is_active', false)
                ->where('types.1.default_severity', null)
                ->where('types.1.can_delete', true)
                ->has('categories', 7)
                ->where('categories.0', ['value' => 'injury', 'label' => 'Injury']));
    }

    public function test_the_it_admin_adds_a_type(): void
    {
        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => 'Elopement',
            'category' => 'security',
            'default_severity' => 'level_2_moderate',
            'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $type = IncidentType::where('name', 'Elopement')->first();
        $this->assertSame('security', $type->category);
        $this->assertSame(Severity::Level2Moderate, $type->default_severity);
        $this->assertTrue($type->is_active);
    }

    public function test_adding_a_type_without_a_default_severity(): void
    {
        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => 'Elopement',
            'category' => 'security',
            'default_severity' => null,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull(IncidentType::where('name', 'Elopement')->first()->default_severity);
    }

    public function test_adding_a_type_is_validated(): void
    {
        IncidentType::factory()->create(['name' => 'Falls']);

        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => 'Falls',
            'category' => 'not-a-category',
            'default_severity' => 'level_9',
            'is_active' => true,
        ])->assertSessionHasErrors(['name', 'category', 'default_severity']);

        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => '',
            'category' => '',
        ])->assertSessionHasErrors(['name', 'category']);

        $this->assertSame(1, IncidentType::count());
    }

    public function test_other_roles_cannot_add_a_type(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->post('/admin/incident-types', ['name' => 'Elopement', 'category' => 'security', 'is_active' => true])
            ->assertForbidden();

        $this->assertSame(0, IncidentType::count());
    }

    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Falls',
            'category' => 'injury',
            'default_severity' => null,
            'is_active' => true,
        ], $overrides);
    }

    public function test_the_it_admin_edits_a_type(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Fall', 'category' => 'injury']);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload([
                'name' => 'Falls',
                'category' => 'clinical',
                'default_severity' => 'level_4_critical',
                'is_active' => false,
            ]))->assertSessionHasNoErrors()->assertRedirect();

        $type->refresh();
        $this->assertSame('Falls', $type->name);
        $this->assertSame('clinical', $type->category);
        $this->assertSame(Severity::Level4Critical, $type->default_severity);
        $this->assertFalse($type->is_active);
    }

    public function test_editing_can_clear_the_default_severity_and_keep_the_same_name(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury', 'default_severity' => Severity::Level3High]);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload())
            ->assertSessionHasNoErrors();

        $this->assertNull($type->fresh()->default_severity);
    }

    public function test_editing_rejects_another_types_name(): void
    {
        IncidentType::factory()->create(['name' => 'Spills']);
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury']);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload(['name' => 'Spills']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Falls', $type->fresh()->name);
    }

    public function test_a_type_in_use_can_still_be_edited_and_switched_off(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury']);
        $this->incidentUsing($type);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload(['is_active' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_other_roles_cannot_edit_a_type(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury']);

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->put("/admin/incident-types/{$type->id}", $this->editPayload(['name' => 'Changed']))
            ->assertForbidden();

        $this->assertSame('Falls', $type->fresh()->name);
    }

    public function test_an_inactive_type_is_not_offered_in_the_report_wizard(): void
    {
        $active = IncidentType::factory()->create(['name' => 'Falls']);
        $inactive = IncidentType::factory()->create(['name' => 'Spills', 'is_active' => false]);

        $this->actingAs(User::factory()->create(['role' => Role::Staff]))->get('/incidents/create')
            ->assertInertia(fn ($page) => $page->has('incidentTypes', 1)->where('incidentTypes.0.id', $active->id));
    }

    public function test_the_it_admin_deletes_an_unused_type(): void
    {
        $type = IncidentType::factory()->create();

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertRedirect();

        $this->assertModelMissing($type);
    }

    public function test_a_type_used_by_an_incident_cannot_be_deleted(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing($type);

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
        $this->assertSame([$type->id], $incident->fresh()->incidentTypes->pluck('id')->all());
    }

    public function test_a_type_on_the_legacy_column_cannot_be_deleted(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing(IncidentType::factory()->create());
        DB::table('incidents')->where('id', $incident->id)->update(['incident_type_id' => $type->id]);

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
    }

    public function test_a_type_used_by_a_recurrence_review_cannot_be_deleted(): void
    {
        $type = IncidentType::factory()->create();
        $this->recurrenceReviewUsing($type);

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
    }

    public function test_other_roles_cannot_delete_a_type(): void
    {
        $type = IncidentType::factory()->create();

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
    }
}
