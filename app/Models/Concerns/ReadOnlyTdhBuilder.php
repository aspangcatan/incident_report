<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * The Eloquent query-builder half of ReadOnlyTdhModel's guard.
 *
 * UserPrivilege::forThisSystem()->update(...)/->delete(), Designation::
 * insert(...)/insertOrIgnore(...)/insertGetId(...)/insertUsing(...)/
 * upsert(...)/truncate()/touch() and increment()/decrement() called on the
 * builder all act straight on the query builder without ever
 * saving/deleting a hydrated model, so they bypass performInsert/
 * performUpdate/performDeleteOnModel/incrementOrDecrement on the model
 * itself. insert/insertOrIgnore/insertGetId/insertUsing are additionally
 * forwarded by the base Eloquent\Builder to the underlying query builder
 * via its $passthru list, so they need an explicit override here too —
 * there is no perform*() hook to lean on for them. updateOrInsert/
 * incrementEach/decrementEach/updateFrom aren't defined on Eloquent\Builder
 * at all (and aren't in $passthru), so Eloquent's __call() forwards them
 * straight to the base query builder — guarded via a __call() override
 * below instead of individual method overrides.
 */
class ReadOnlyTdhBuilder extends Builder
{
    /**
     * Write methods that Eloquent\Builder has no method of its own for,
     * and so forwards to the base query builder via __call(). None of
     * these are in Eloquent\Builder's $passthru list.
     */
    private const FORWARDED_WRITES = ['updateOrInsert', 'incrementEach', 'decrementEach', 'updateFrom'];

    public function update(array $values)
    {
        $this->guardTdhWrite();

        return parent::update($values);
    }

    public function delete()
    {
        $this->guardTdhWrite();

        return parent::delete();
    }

    public function forceDelete()
    {
        $this->guardTdhWrite();

        return parent::forceDelete();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->guardTdhWrite();

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->guardTdhWrite();

        return parent::decrement($column, $amount, $extra);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->guardTdhWrite();

        return parent::upsert($values, $uniqueBy, $update);
    }

    public function insert(array $values)
    {
        $this->guardTdhWrite();

        return $this->toBase()->insert($values);
    }

    public function insertOrIgnore(array $values)
    {
        $this->guardTdhWrite();

        return $this->toBase()->insertOrIgnore($values);
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $this->guardTdhWrite();

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function insertUsing(array $columns, $query)
    {
        $this->guardTdhWrite();

        return $this->toBase()->insertUsing($columns, $query);
    }

    public function truncate()
    {
        $this->guardTdhWrite();

        $this->toBase()->truncate();
    }

    public function touch($column = null)
    {
        $this->guardTdhWrite();

        return parent::touch($column);
    }

    /**
     * updateOrInsert/incrementEach/decrementEach/updateFrom have no method
     * of their own on Eloquent\Builder and aren't in its $passthru list,
     * so the base __call() would otherwise forward them straight to the
     * underlying query builder, unguarded.
     */
    public function __call($method, $parameters)
    {
        if (in_array($method, self::FORWARDED_WRITES, true)) {
            $this->guardTdhWrite();
        }

        return parent::__call($method, $parameters);
    }

    private function guardTdhWrite(): void
    {
        $model = $this->getModel();

        $model::guardTdhWrite($model);
    }
}
