<?php

namespace App\Models;

use App\Support\Realtime;
use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;
use Illuminate\Support\Facades\DB;

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

    /** Every new notification also reaches the person's open browser at once (realtime/server.js). */
    protected static function booted(): void
    {
        static::created(function (self $notification) {
            // After the surrounding transaction commits, so the page reload sees the new data.
            DB::connection($notification->getConnectionName())->afterCommit(fn () => Realtime::push(
                [$notification->notifiable_id],
                'notification',
                ['id' => $notification->id, 'type' => class_basename($notification->type), 'data' => $notification->data],
            ));
        });
    }
}
