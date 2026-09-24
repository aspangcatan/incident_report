<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * Pins notifications to this app's own database. Without this, Laravel's
 * newRelatedInstance() would inherit the tdh_user connection from the
 * User model and look for tdh_user.notifications.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    public function getConnectionName()
    {
        return config('database.default');
    }
}
