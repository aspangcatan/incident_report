<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider roles */
    public function test_sidebar_flags_match_the_role(Role $role, array $expected): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('auth.can', $expected));
    }

    public static function roles(): array
    {
        $flags = fn (bool $all, bool $inv, bool $capa, bool $analytics, bool $admin) => [
            'viewAllIncidents' => $all,
            'investigationWorkspace' => $inv,
            'capaOperations' => $capa,
            'viewAnalytics' => $analytics,
            'administration' => $admin,
        ];

        return [
            'staff' => [Role::Staff, $flags(false, false, false, false, false)],
            'investigator' => [Role::Investigator, $flags(true, true, false, false, false)],
            'supervisor' => [Role::Supervisor, $flags(true, true, true, true, false)],
            'department head' => [Role::DepartmentHead, $flags(true, true, true, true, false)],
            'qso' => [Role::QualitySafetyOfficer, $flags(true, true, true, true, true)],
            'administrator' => [Role::Administrator, $flags(false, false, false, false, true)],
            'management' => [Role::Management, $flags(true, false, false, true, false)],
            'leadership' => [Role::Leadership, $flags(true, false, false, true, false)],
            'cqi committee' => [Role::CqiCommittee, $flags(true, false, false, true, false)],
        ];
    }
}
