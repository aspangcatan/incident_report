<?php

namespace Database\Seeders;

use App\Enums\Severity;
use App\Models\IncidentType;
use Illuminate\Database\Seeder;

class IncidentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Medication Error (Infusion Rate / Dose Mismatch)', 'category' => 'medication', 'default_severity' => Severity::Level3High],
            ['name' => 'Patient Safety Incident (Fall / Slippage)', 'category' => 'clinical_safety', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Sentinel Event (Unexpected Death / Serious Harm)', 'category' => 'clinical_safety', 'default_severity' => Severity::Level4CriticalSentinel],
            ['name' => 'Adverse Drug Reaction (ADR / Anaphylaxis)', 'category' => 'medication', 'default_severity' => Severity::Level3High],
            ['name' => 'Occupational / Staff Needle Stick Injury', 'category' => 'occupational', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Equipment / Biomedical Device Failure', 'category' => 'facility_biomed', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Security Breach / Physical Violence', 'category' => 'security', 'default_severity' => Severity::Level3High],
        ];

        foreach ($types as $type) {
            IncidentType::firstOrCreate(['name' => $type['name']], $type);
        }
    }
}
