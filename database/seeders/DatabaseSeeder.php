<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Users and departments come from the shared tdh_user database
        // (read-only); only this app's own reference data is seeded.
        $this->call([
            IncidentTypeSeeder::class,
            ContributingFactorSeeder::class,
        ]);
    }
}
