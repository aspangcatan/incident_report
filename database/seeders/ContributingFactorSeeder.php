<?php

namespace Database\Seeders;

use App\Models\ContributingFactor;
use Illuminate\Database\Seeder;

class ContributingFactorSeeder extends Seeder
{
    public function run(): void
    {
        $factors = [
            ['label' => 'High Patient Surge / ER Saturation', 'category' => 'staffing'],
            ['label' => 'Shift Handover Interruption', 'category' => 'staffing'],
            ['label' => 'Omitted Dual Verification', 'category' => 'procedure'],
            ['label' => 'Device Screen Dimming Fault', 'category' => 'equipment'],
            ['label' => 'Ambient Noise / Environmental Distraction', 'category' => 'environment'],
            ['label' => 'Workstation / System Downtime', 'category' => 'equipment'],
            ['label' => 'Inadequate Labeling or Documentation', 'category' => 'procedure'],
            ['label' => 'Communication Breakdown Between Units', 'category' => 'staffing'],
        ];

        foreach ($factors as $factor) {
            ContributingFactor::firstOrCreate(['label' => $factor['label']], $factor);
        }
    }
}
