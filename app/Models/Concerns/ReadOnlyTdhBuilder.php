<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * The Eloquent query-builder half of ReadOnlyTdhModel's guard.
 *
 * UserPrivilege::forThisSystem()->update(...)/->delete(), Designation::
 * insert(...)/insertOrIgnore(...)/insertGetId(...)/insertUsing(...)/
 * upsert(...)/truncate() and increment()/decrement() called on the
 * builder all act straight on the query builder without ever
 * saving/deleting a hydrated model, so they bypass performInsert/
 * performUpdate/performDeleteOnModel/incrementOrDecrement on the model
 * itself. insert/insertOrIgnore/insertGetId/insertUsing are additionally
 * forwarded by the base Eloquent\Builder to the underlying query builder
 * via its $passthru list, so they need an explicit override here too —
 * there is no perform*() hook to lean on for them.
 */
class ReadOnlyTdhBuilder extends Builder
{
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

    private function guardTdhWrite(): void
    {
        $model = $this->getModel();

        $model::guardTdhWrite($model);
    }
}
