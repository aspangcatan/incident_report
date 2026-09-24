<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds tdh_user.section rows in the test replica. Accepts the legacy
 * `name` attribute (maps to `description`) so existing call sites like
 * Department::factory()->create(['name' => 'Surgery']) keep working.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Department>
 */
class DepartmentFactory extends Factory
{
    public function definition()
    {
        return [
            'description' => $this->faker->unique()->company() . ' Department',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'division' => 0,
            'head' => 0,
        ];
    }

    public function newModel(array $attributes = [])
    {
        if (array_key_exists('name', $attributes)) {
            $attributes['description'] = $attributes['name'];
            unset($attributes['name']);
        }

        return parent::newModel($attributes);
    }
}
