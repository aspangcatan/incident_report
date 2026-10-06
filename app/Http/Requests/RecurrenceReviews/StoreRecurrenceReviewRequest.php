<?php

namespace App\Http\Requests\RecurrenceReviews;

use App\Models\Department;
use App\Models\RecurrenceReview;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecurrenceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RecurrenceReview::class);
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', Department::selectableRule()],
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'assigned_to' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! self::assignees((int) $this->input('department_id'))->contains('id', (int) $value)) {
                        $fail("Choose the department's Head or Safety Focal Person.");
                    }
                },
            ],
            'due_date' => ['required', 'date', 'after:today'],
            'cqi_notes' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['assigned_to' => 'assigned to', 'cqi_notes' => 'what to look into'];
    }

    /** Who can own a review for a department: its active Head (section head) and Safety Focal Person(s). */
    public static function assignees(int $departmentId)
    {
        $headId = Department::whereKey($departmentId)->value('head');

        return User::active()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->withRole(\App\Enums\Role::Supervisor)->where('section', $departmentId))
                ->when($headId, fn ($q) => $q->orWhere('id', $headId)))
            ->orderByName()
            ->get();
    }
}
