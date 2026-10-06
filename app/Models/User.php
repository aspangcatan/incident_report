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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A person = a row in the shared tdh_user.users table (read-only).
 *
 * - role: tdh_user.user_priv.level for this system's syscode (config('tdh.syscode'));
 *   no row, or an unrecognised level, means Role::Staff. Matched case- and
 *   surrounding-space-insensitively, as the latin1_swedish_ci column does in SQL.
 * - department_id: tdh_user.users.section (0 = none).
 * - is_active: status === '1'.
 *
 * Remember-me is disabled (getRememberTokenName() returns ''): remember_token
 * is shared with other hospital systems and must never be rotated from here.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, ReadOnlyTdhModel;

    /**
     * Columns this app reads. Never loads signature/picture/api_token/security_pin.
     *
     * Applied by the 'tdhColumns' global scope below, with two caveats:
     * - fresh()/refresh() bypass global scopes and so `select *`. That only
     *   costs memory (the blobs are loaded); $visible still keeps every
     *   sensitive field out of serialization.
     * - The scope replaces the *implicit* column list, so an explicit
     *   get(['id', ...]) / first([...]) column list is overridden by it.
     *   Callers needing specific columns must use select() instead.
     */
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
    protected $visible = ['id', 'name', 'role', 'department_id', 'designation_title'];

    /** Memo for getRoleAttribute(), valid only while $roleSource is the loaded privilege. */
    private ?Role $resolvedRole = null;

    private ?UserPrivilege $roleSource = null;

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

    /**
     * tdh_user name columns hold junk ('-', '.', 'N/A', leading spaces), and
     * `title` holds either an honorific ('Dr.') or post-nominals ('MD, FPSGS').
     * Honorifics prefix the name; anything else is appended after a comma.
     */
    public function getNameAttribute(): string
    {
        $title = self::namePart($this->title);
        $mname = self::namePart($this->mname);
        $middleInitial = $mname !== null && preg_match('/\p{L}/u', $mname, $letter) ? mb_strtoupper($letter[0]) . '.' : null;
        $honorific = $title !== null && preg_match('/^(Dr|Engr|Atty|Arch|Mr|Mrs|Ms|Prof|Rev|Hon)\.?$/i', $title);

        $name = collect([
            $honorific ? $title : null,
            self::namePart($this->fname),
            $middleInitial,
            self::namePart($this->lname),
            self::namePart($this->suffix),
        ])->filter()->implode(' ');

        return $title !== null && ! $honorific ? "{$name}, {$title}" : $name;
    }

    /** A trimmed name part, or null when it is empty, punctuation only, or a N/A-style placeholder. */
    private static function namePart(?string $part): ?string
    {
        $part = trim((string) $part);

        if ($part === '' || preg_match('/^[\p{P}\s]+$/u', $part) || in_array(strtolower($part), ['n/a', 'na', 'none'], true)) {
            return null;
        }

        return $part;
    }

    /**
     * Memoized per instance, keyed on the loaded privilege model: the unknown-
     * level warning logs once, while unsetRelation()/load()/setRelation() on
     * 'privilege' (e.g. in UserFactory) swap in a new instance and so force a
     * fresh resolution without any explicit reset.
     */
    public function getRoleAttribute(): Role
    {
        $privilege = $this->privilege;

        if ($this->resolvedRole === null || $this->roleSource !== $privilege) {
            $this->roleSource = $privilege;
            $this->resolvedRole = $this->resolveRole($privilege?->level);
        }

        return $this->resolvedRole;
    }

    private function resolveRole(?string $level): Role
    {
        if ($level === null) {
            return Role::Staff;
        }

        $role = Role::tryFrom(strtolower(trim($level)));

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

    private ?array $leadershipDepartmentIdsCache = null;

    /** Departments a Medical/Nursing/Ancillary Leadership user oversees (leadership_departments). */
    public function leadershipDepartmentIds(): array
    {
        return $this->leadershipDepartmentIdsCache ??= DB::table('leadership_departments')
            ->where('user_id', $this->id)
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private ?array $headedDepartmentIdsCache = null;

    /** Sections whose tdh_user.section.head is this user — they are its Department/Service Head. */
    public function headedDepartmentIds(): array
    {
        return $this->headedDepartmentIdsCache ??= Department::where('head', $this->id)
            ->where('description', '!=', '-') // placeholder rows, same rule as Department::scopeSelectable
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isDepartmentHead(): bool
    {
        return $this->headedDepartmentIds() !== [];
    }

    public function isHeadOf(?int $departmentId): bool
    {
        return $departmentId !== null && in_array($departmentId, $this->headedDepartmentIds(), true);
    }

    /** Who can be assigned to investigate an incident: an active IR investigator, or active staff of its department. */
    public function canInvestigate(Incident $incident): bool
    {
        return $this->is_active
            && ($this->role === Role::Investigator
                || ($incident->department_id !== null && $this->department_id === $incident->department_id));
    }

    /**
     * Who may be the responsible person on a CAPA for this incident: active
     * staff of the incident's department (the department does the work).
     */
    public function canBeResponsibleFor(Incident $incident): bool
    {
        return $this->is_active
            && $incident->department_id !== null
            && $this->department_id === $incident->department_id;
    }

    /** Validation: an id of an active tdh user (see scopeActive()). */
    public static function activeRule(): Exists
    {
        return Rule::exists(config('tdh.connection') . '.users', 'id')->where('status', '1');
    }

    public function scopeOrderByName(Builder $query): Builder
    {
        // TRIM: some tdh names carry leading spaces, which would sort them first.
        return $query
            ->orderByRaw('TRIM(' . $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('lname')) . ')')
            ->orderByRaw('TRIM(' . $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('fname')) . ')');
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

        $idsWithLevels = fn (array $levels) => UserPrivilege::query()->forThisSystem()->whereIn('level', $levels)->whereNotNull('user_id')->select('user_id');

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
