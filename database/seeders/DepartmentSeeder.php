<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Emergency Medicine Department', 'code' => 'ED'],
            ['name' => 'Intensive Care Unit', 'code' => 'ICU'],
            ['name' => 'Main Operating Theater Complex', 'code' => 'OR'],
            ['name' => 'General Inpatient Ward (Internal Medicine)', 'code' => 'IM-WARD'],
            ['name' => 'Department of Obstetrics & Gynecology', 'code' => 'OB-GYN'],
            ['name' => 'Biochemical & Pathology Laboratory', 'code' => 'LAB'],
            ['name' => 'Nursing Service Department', 'code' => 'NURSING'],
            ['name' => 'Biomedical Engineering Division', 'code' => 'BIOMED'],
            ['name' => 'Office of the Medical Center Chief & Quality Management Division', 'code' => 'QMD'],
        ];

        foreach ($departments as $department) {
            Department::firstOrCreate(['code' => $department['code']], $department);
        }
    }
}
