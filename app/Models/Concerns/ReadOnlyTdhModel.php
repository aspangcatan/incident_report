<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Binds a model to the shared tdh_user database (config('tdh.connection'))
 * and refuses every write on it, whether issued through a hydrated model
 * (save/create/update/delete, including the "quiet" and withoutEvents
 * variants, and increment/decrement) or straight through the Eloquent
 * query builder (UserPrivilege::forThisSystem()->update(...)/->delete(),
 * Designation::insert(...)/upsert(...)/truncate(), etc. — see
 * ReadOnlyTdhBuilder). tdh_user is shared by ~20 hospital systems; this
 * app only ever reads it.
 *
 * Writes are allowed only when running tests against the in-memory SQLite
 * replica (tests/Support/TdhTestSchema.php), so factories can seed it.
 * Raw `DB::connection('user')->...` query-builder writes are NOT
 * intercepted by any of this — never issue them against the tdh
 * connection. Because of that, the live connection's MySQL account must
 * itself be SELECT-only (see .env.example / config/tdh.php) as the real
 * enforcement boundary in production.
 *
 * The saving/deleting event listeners below are redundant with the
 * performInsert/performUpdate/performDeleteOnModel/incrementOrDecrement
 * overrides for the paths Eloquent fires events on, but are cheap
 * belt-and-braces and keep the guard front-and-center for anyone skimming
 * model events.
 */
trait ReadOnlyTdhModel
{
    public static function bootReadOnlyTdhModel(): void
    {
        foreach (['saving', 'deleting'] as $event) {
            static::$event(function (Model $model) {
                static::guardTdhWrite($model);
            });
        }
    }

    public function getConnectionName()
    {
        return config('tdh.connection');
    }

    /**
     * @return \App\Models\Concerns\ReadOnlyTdhBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new ReadOnlyTdhBuilder($query);
    }

    protected function performInsert(Builder $query)
    {
        static::guardTdhWrite($this);

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        static::guardTdhWrite($this);

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        static::guardTdhWrite($this);

        parent::performDeleteOnModel();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        static::guardTdhWrite($this);

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    /**
     * Public so ReadOnlyTdhBuilder — a plain Eloquent\Builder subclass, not
     * a user of this trait — can call it via the model instance/class it
     * is building for.
     */
    public static function guardTdhWrite(Model $model): void
    {
        if (! static::tdhWritesAllowed($model)) {
            throw new LogicException('tdh_user is read-only from incident-report.');
        }
    }

    protected static function tdhWritesAllowed(Model $model): bool
    {
        return app()->environment('testing')
            && $model->getConnection()->getDriverName() === 'sqlite';
    }
}
