<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Models\UserPrivilege;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * Builds tdh_user.users rows in the test replica. For compatibility with
 * the existing suite it accepts two non-column attributes:
 *   - 'role'          => Role|string  -> writes an IR user_priv row (none when omitted)
 *   - 'department_id' => ?int         -> stored as users.section (null -> 0)
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /** @var WeakMap<User, Role>|null */
    private static ?WeakMap $pendingRoles = null;

    public function definition()
    {
        return [
            'fname' => $this->faker->firstName(),
            'mname' => $this->faker->lastName(),
            'lname' => $this->faker->lastName(),
            'username' => $this->faker->unique()->userName(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'designation' => 0,
            'division' => 0,
            'section' => 0,
            'status' => '1',
        ];
    }

    /** Make the user the head (tdh_user.section.head) of these sections. */
    public function headOf(Department|int ...$departments): static
    {
        return $this->afterCreating(function (User $user) use ($departments) {
            foreach ($departments as $department) {
                Department::whereKey($department instanceof Department ? $department->id : $department)
                    ->update(['head' => $user->id]);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(['status' => '0']);
    }

    /**
     * Laravel 9's Factory::makeInstance() passes the fully expanded
     * attributes here, so this is where the non-column 'role' and
     * 'department_id' keys are translated before the model is built.
     */
    public function newModel(array $attributes = [])
    {
        $role = $attributes['role'] ?? null;
        unset($attributes['role']);

        if (array_key_exists('department_id', $attributes)) {
            $attributes['section'] = $attributes['department_id'] ?? 0;
            unset($attributes['department_id']);
        }

        $model = parent::newModel($attributes);

        if ($role !== null) {
            self::pendingRoles()[$model] = $role instanceof Role ? $role : Role::from($role);
        }

        return $model;
    }

    public function configure()
    {
        return $this->afterCreating(function (User $user) {
            $role = self::pendingRoles()[$user] ?? null;

            if ($role === null) {
                return;
            }

            UserPrivilege::create([
                'user_id' => $user->id,
                'syscode' => config('tdh.syscode'),
                'level' => $role->value,
            ]);

            $user->unsetRelation('privilege');
        });
    }

    private static function pendingRoles(): WeakMap
    {
        return self::$pendingRoles ??= new WeakMap();
    }
}
