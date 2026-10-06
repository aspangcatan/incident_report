<?php

namespace Database\Seeders;

use App\Enums\Severity;
use App\Models\IncidentType;
use Illuminate\Database\Seeder;

/**
 * The hospital's incident type list (given by the client, 2026-09-29), with
 * default severities set 2026-10-06. firstOrCreate never overwrites a type that
 * already exists, so the IT Admin's later changes are kept.
 */
class IncidentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Needle Pricks', 'category' => 'injury', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Back Injury', 'category' => 'injury', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Bodily Injury', 'category' => 'injury', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Falls', 'category' => 'injury', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Physical Trauma', 'category' => 'injury', 'default_severity' => Severity::Level3High],
            ['name' => 'Treatment Problem Delay', 'category' => 'clinical', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Treatment Error', 'category' => 'clinical', 'default_severity' => Severity::Level3High],
            ['name' => 'Chemical/Biological/Radioactive Exposure', 'category' => 'exposure', 'default_severity' => Severity::Level3High],
            ['name' => 'Physical/Verbal Abuse', 'category' => 'security', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Property Damage/Loss', 'category' => 'property', 'default_severity' => Severity::Level1Low],
            ['name' => 'Theft/Burglary', 'category' => 'security', 'default_severity' => Severity::Level1Low],
            ['name' => 'Fires', 'category' => 'environment', 'default_severity' => Severity::Level3High],
            ['name' => 'Floods', 'category' => 'environment', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Spills', 'category' => 'environment', 'default_severity' => Severity::Level1Low],
            ['name' => 'Equipment', 'category' => 'property', 'default_severity' => Severity::Level1Low],
            ['name' => 'Neglect', 'category' => 'conduct', 'default_severity' => Severity::Level3High],
            ['name' => 'Breach of Policies', 'category' => 'conduct', 'default_severity' => Severity::Level1Low],
            ['name' => 'Breach of Confidentiality', 'category' => 'conduct', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Breach of Safety/Security', 'category' => 'security', 'default_severity' => Severity::Level2Moderate],
            ['name' => 'Documentation Error', 'category' => 'clinical', 'default_severity' => Severity::Level1Low],
            ['name' => 'Breach in Scope of Practice', 'category' => 'conduct', 'default_severity' => Severity::Level2Moderate],
        ];

        foreach ($types as $type) {
            IncidentType::firstOrCreate(['name' => $type['name']], $type);
        }
    }
}
