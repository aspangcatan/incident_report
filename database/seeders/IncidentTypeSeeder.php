<?php

namespace Database\Seeders;

use App\Models\IncidentType;
use Illuminate\Database\Seeder;

/** The hospital's incident type list (given by the client, 2026-09-29). */
class IncidentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Needle Pricks', 'category' => 'injury'],
            ['name' => 'Back Injury', 'category' => 'injury'],
            ['name' => 'Bodily Injury', 'category' => 'injury'],
            ['name' => 'Falls', 'category' => 'injury'],
            ['name' => 'Physical Trauma', 'category' => 'injury'],
            ['name' => 'Treatment Problem Delay', 'category' => 'clinical'],
            ['name' => 'Treatment Error', 'category' => 'clinical'],
            ['name' => 'Chemical/Biological/Radioactive Exposure', 'category' => 'exposure'],
            ['name' => 'Physical/Verbal Abuse', 'category' => 'security'],
            ['name' => 'Property Damage/Loss', 'category' => 'property'],
            ['name' => 'Theft/Burglary', 'category' => 'security'],
            ['name' => 'Fires', 'category' => 'environment'],
            ['name' => 'Floods', 'category' => 'environment'],
            ['name' => 'Spills', 'category' => 'environment'],
            ['name' => 'Equipment', 'category' => 'property'],
            ['name' => 'Neglect', 'category' => 'conduct'],
            ['name' => 'Breach of Policies', 'category' => 'conduct'],
            ['name' => 'Breach of Confidentiality', 'category' => 'conduct'],
            ['name' => 'Breach of Safety/Security', 'category' => 'security'],
            ['name' => 'Documentation Error', 'category' => 'clinical'],
            ['name' => 'Breach in Scope of Practice', 'category' => 'conduct'],
        ];

        foreach ($types as $type) {
            IncidentType::firstOrCreate(['name' => $type['name']], $type);
        }
    }
}
