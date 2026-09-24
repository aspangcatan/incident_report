<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * A person = a row in the shared tdh_user.users table (read-only).
 *
 * - role: tdh_user.user_priv.level for this system's syscode (config('tdh.syscode'));
 *   no row, or an unrecognised level, means Role::Staff.
 * - department_id: tdh_user.users.section (0 = none).
 * - is_active: status === '1'.
 *
 * Remember-me is disabled (getRememberTokenName() returns ''): remember_token
 * is shared with other hospital systems and must never be rotated from here.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, ReadOnlyTdhModel;

    /** Columns this app reads. Never loads signature/picture/api_token/security_pin. */
    private const COLUMNS = [
        'id', 'fname', 'mname', 'lname', 'suffix', 'title', 'username', 'password',
        'designation', 'other_designation', 'section', 'status',
    ];

    protected $table = 'users';

    protected $with = ['privilege', 'designationRecord'];

    protected $casts = [
        'designation' => 'integer',
        'section' => 'integer',
    ];

    protected $appends = ['name', 'role', 'department_id', 'designation_title'];

    /** Whitelist: tdh rows carry password hashes, tokens, PINs and signatures. */
    protected $visible = ['id', 'username', 'name', 'role', 'department_id', 'designation_title'];

    protected static function booted(): void
    {
        static::addGlobalScope('tdhColumns', function (Builder $query) {
            if ($query->getQuery()->columns === null) {
                $query->select($query->getModel()->qualifyColumns(self::COLUMNS));
            }
        });
    }

    public function privilege(): HasOne
    {
        return $this->hasOne(UserPrivilege::class, 'user_id')->forThisSystem()->oldest('id');
    }

    public function designationRecord(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'section');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    public function getNameAttribute(): string
    {
        $middleInitial = filled($this->mname) ? mb_substr(trim($this->mname), 0, 1) . '.' : null;

        return collect([$this->title, $this->fname, $middleInitial, $this->lname, $this->suffix])
            ->map(fn ($part) => is_string($part) ? trim($part) : $part)
            ->filter()
            ->implode(' ');
    }

    public function getRoleAttribute(): Role
    {
        $level = $this->privilege?->level;

        if ($level === null) {
            return Role::Staff;
        }

        $role = Role::tryFrom($level);

        if ($role === null) {
            Log::warning('Unrecognised IR privilege level in tdh_user.user_priv; treating as Staff.', [
                'user_id' => $this->id,
                'level' => $level,
            ]);

            return Role::Staff;
        }

        return $role;
    }

    public function getDepartmentIdAttribute(): ?int
    {
        $section = $this->attributes['section'] ?? null;

        return $section ? (int) $section : null;
    }

    public function getIsActiveAttribute(): bool
    {
        return (string) ($this->attributes['status'] ?? '') === '1';
    }

    public function getDesignationTitleAttribute(): ?string
    {
        return $this->designationRecord?->description ?: ($this->other_designation ?: null);
    }

    public function getRememberTokenName()
    {
        return '';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), '1');
    }

    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn('lname'))->orderBy($this->qualifyColumn('fname'));
    }

    /**
     * Users whose IR role is any of $roles. Role::Staff also matches users with
     * no IR row (or an unrecognised level) — i.e. anyone not holding an
     * elevated IR level — mirroring getRoleAttribute().
     *
     * @param  Role|string|array<Role|string>  $roles
     */
    public function scopeWithRole(Builder $query, Role|string|array $roles): Builder
    {
        $roles = collect(Arr::wrap($roles))->map(fn ($role) => $role instanceof Role ? $role : Role::from($role));
        $elevated = $roles->reject(fn (Role $role) => $role === Role::Staff)->map(fn (Role $role) => $role->value)->values()->all();
        $allElevated = collect(Role::cases())->reject(fn (Role $role) => $role === Role::Staff)->map(fn (Role $role) => $role->value)->values()->all();

        $idsWithLevels = fn (array $levels) => UserPrivilege::query()->forThisSystem()->whereIn('level', $levels)->select('user_id');

        return $query->where(function (Builder $query) use ($roles, $elevated, $allElevated, $idsWithLevels) {
            if ($elevated !== []) {
                $query->whereIn($this->qualifyColumn('id'), $idsWithLevels($elevated));
            }

            if ($roles->contains(Role::Staff)) {
                $query->orWhereNotIn($this->qualifyColumn('id'), $idsWithLevels($allElevated));
            }
        });
    }
}
