<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Binds a model to the shared tdh_user database (config('tdh.connection'))
 * and refuses every Eloquent save/delete on it. tdh_user is shared by ~20
 * hospital systems; this app only ever reads it.
 *
 * Writes are allowed only when running tests against the in-memory SQLite
 * replica (tests/Support/TdhTestSchema.php), so factories can seed it.
 * Raw query-builder writes are not intercepted — never issue them against
 * the tdh connection.
 */
trait ReadOnlyTdhModel
{
    public static function bootReadOnlyTdhModel(): void
    {
        foreach (['saving', 'deleting'] as $event) {
            static::$event(function (Model $model) {
                if (! static::tdhWritesAllowed($model)) {
                    throw new LogicException('tdh_user is read-only from incident-report.');
                }
            });
        }
    }

    public function getConnectionName()
    {
        return config('tdh.connection');
    }

    protected static function tdhWritesAllowed(Model $model): bool
    {
        return app()->environment('testing')
            && $model->getConnection()->getDriverName() === 'sqlite';
    }
}
