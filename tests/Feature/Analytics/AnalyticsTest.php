<?php

namespace Tests\Feature\Analytics;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_qso_administrator_and_management_can_view_analytics(): void
    {
        $this->assertTrue(User::factory()->create(['role' => Role::QualitySafetyOfficer])->can('viewAnalytics'));
        $this->assertTrue(User::factory()->create(['role' => Role::Administrator])->can('viewAnalytics'));
        $this->assertTrue(User::factory()->create(['role' => Role::Management])->can('viewAnalytics'));
    }

    public function test_supervisor_and_department_head_can_view_analytics(): void
    {
        $this->assertTrue(User::factory()->create(['role' => Role::Supervisor])->can('viewAnalytics'));
        $this->assertTrue(User::factory()->create(['role' => Role::DepartmentHead])->can('viewAnalytics'));
    }

    public function test_staff_and_investigator_cannot_view_analytics(): void
    {
        $this->assertFalse(User::factory()->create(['role' => Role::Staff])->can('viewAnalytics'));
        $this->assertFalse(User::factory()->create(['role' => Role::Investigator])->can('viewAnalytics'));
    }
}
