<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Which departments each Medical/Nursing/Ancillary Leadership user oversees. */
class LeadershipDepartmentController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manageLeadership');

        return Inertia::render('Admin/Leadership', [
            'leaders' => User::active()->withRole(Role::Leadership)->orderByName()->get()
                ->map(fn (User $leader) => [
                    'id' => $leader->id,
                    'name' => $leader->name,
                    'department_ids' => $leader->leadershipDepartmentIds(),
                ])->values(),
            'departments' => Department::options(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manageLeadership');
        abort_unless($user->role === Role::Leadership, 404);

        $ids = $request->validate([
            'department_ids' => ['present', 'array'],
            'department_ids.*' => ['integer', 'distinct', Department::selectableRule()],
        ])['department_ids'];

        DB::transaction(function () use ($user, $ids) {
            DB::table('leadership_departments')->where('user_id', $user->id)->delete();
            DB::table('leadership_departments')->insert(collect($ids)->map(fn ($id) => [
                'user_id' => $user->id,
                'department_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });

        return back()->with('success', "Departments saved for {$user->name}.");
    }
}
