<?php

namespace App\Models;

use App\Enums\AlertUrgency;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A safety alert from the CQI Office to all staff ('all') or selected departments ('departments'). */
class SafetyAlert extends Model
{
    protected $fillable = ['title', 'message', 'urgency', 'audience', 'incident_id', 'created_by'];

    protected $casts = [
        'urgency' => AlertUrgency::class,
        'incident_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(SafetyAlertDepartment::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(SafetyAlertAcknowledgement::class);
    }

    /** 'all', or a chosen department the user works in or heads (tdh section head). */
    public function isAddressedTo(User $user): bool
    {
        return $this->audience === 'all'
            || ($user->department_id !== null && $this->departments->contains('department_id', $user->department_id))
            || $this->departments->contains(fn ($department) => $user->isHeadOf((int) $department->department_id));
    }

    public function isAcknowledgedBy(User $user): bool
    {
        return $this->acknowledgements()->where('user_id', $user->id)->exists();
    }

    /** Alerts meant for this user (the CQI Office also sees every alert it issued). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === Role::QualitySafetyOfficer) {
            return $query;
        }

        return $query->addressedTo($user);
    }

    public function scopeAddressedTo(Builder $query, User $user): Builder
    {
        $departmentIds = array_values(array_unique(array_filter([$user->department_id, ...$user->headedDepartmentIds()])));

        return $query->where(fn (Builder $q) => $q
            ->where('audience', 'all')
            ->when($departmentIds !== [], fn (Builder $q) => $q->orWhereHas(
                'departments',
                fn (Builder $d) => $d->whereIn('department_id', $departmentIds)
            )));
    }

    public function scopeNotAcknowledgedBy(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('acknowledgements', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    /** Active users this alert is addressed to: everyone, or the chosen departments' staff and heads. */
    public function recipients(): Builder
    {
        $query = User::active();

        if ($this->audience === 'all') {
            return $query;
        }

        $departmentIds = $this->departments->pluck('department_id');
        $headIds = Department::whereIn('id', $departmentIds)->where('head', '>', 0)->pluck('head');

        return $query->where(fn (Builder $q) => $q->whereIn('section', $departmentIds)->orWhereIn('id', $headIds));
    }
}
