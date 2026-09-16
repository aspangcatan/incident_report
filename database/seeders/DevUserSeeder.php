<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local-development-only accounts, standing in for the hospital's real
 * user-management system (tdh_user.users) until that integration is
 * scoped. See docs/architecture.md §2.1 and §9. Do not treat this seeder
 * as the app's real user provisioning path.
 */
class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        $ed = Department::where('code', 'ED')->first();
        $qmd = Department::where('code', 'QMD')->first();
        $biomed = Department::where('code', 'BIOMED')->first();

        $users = [
            [
                'name' => 'Dr. Maria Althea Ramos',
                'email' => 'admin@hopss.test',
                'role' => Role::Administrator,
                'designation' => 'Quality & Safety Officer / HOPSS Chair',
                'department_id' => $qmd?->id,
            ],
            [
                'name' => 'Nurse Ronald Dela Cruz',
                'email' => 'staff@hopss.test',
                'role' => Role::Staff,
                'designation' => 'Attending Staff Nurse',
                'department_id' => $ed?->id,
            ],
            [
                'name' => 'Head Nurse M. Lim',
                'email' => 'supervisor@hopss.test',
                'role' => Role::Supervisor,
                'designation' => 'ER Nursing Supervisor',
                'department_id' => $ed?->id,
            ],
            [
                'name' => 'Dr. Juan Santos',
                'email' => 'investigator@hopss.test',
                'role' => Role::Investigator,
                'designation' => 'Lead Investigator (CQI)',
                'department_id' => $qmd?->id,
            ],
            [
                'name' => 'Engr. R. Bautista',
                'email' => 'depthead@hopss.test',
                'role' => Role::DepartmentHead,
                'designation' => 'Biomedical Safety Officer',
                'department_id' => $biomed?->id,
            ],
            [
                'name' => 'Management Viewer',
                'email' => 'management@hopss.test',
                'role' => Role::Management,
                'designation' => 'Executive Management',
                'department_id' => $qmd?->id,
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user + ['password' => Hash::make('password')]
            );
        }
    }
}
