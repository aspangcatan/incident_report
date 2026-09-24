<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A hospital unit = a row in tdh_user.section (read-only). Users belong to
 * one via tdh_user.users.section; incidents store its id in department_id.
 * tdh_user.section has no active flag, so every section is selectable
 * except placeholder rows whose description is '-'.
 */
class Department extends Model
{
    use HasFactory, ReadOnlyTdhModel;

    protected $table = 'section';

    protected $fillable = ['division', 'description', 'code', 'head', 'subsection'];

    protected $casts = [
        'division' => 'integer',
        'head' => 'integer',
    ];

    protected $appends = ['name'];

    protected $visible = ['id', 'code', 'name'];

    public function getNameAttribute(): string
    {
        return (string) $this->description;
    }

    /** Named headUser, not head: `head` is the raw column this relation reads. */
    public function headUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'section');
    }

    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('description', '!=', '-')->orderBy('description');
    }

    /** Validation: an id of a real (non-placeholder) section, like options(). */
    public static function selectableRule(): Exists
    {
        return Rule::exists(config('tdh.connection') . '.section', 'id')
            ->where(fn ($query) => $query->where('description', '!=', '-'));
    }

    /** @return Collection<int, array{id: int, name: string}> dropdown options */
    public static function options(): Collection
    {
        return static::selectable()
            ->get(['id', 'description'])
            ->map(fn (Department $department) => ['id' => $department->id, 'name' => $department->name])
            ->values();
    }
}
