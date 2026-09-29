<?php

namespace App\Services;

use App\Models\SafetyAlert;
use App\Models\User;
use App\Notifications\SafetyAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SafetyAlertService
{
    /** Create the alert and notify everyone it is addressed to. */
    public function issue(User $issuer, array $data): SafetyAlert
    {
        $alert = DB::transaction(function () use ($issuer, $data) {
            $alert = SafetyAlert::create([
                'title' => $data['title'],
                'message' => $data['message'],
                'urgency' => $data['urgency'],
                'audience' => $data['audience'],
                'incident_id' => $data['incident_id'] ?? null,
                'created_by' => $issuer->id,
            ]);

            if ($data['audience'] === 'departments') {
                $alert->departments()->createMany(
                    collect($data['department_ids'])->map(fn ($id) => ['department_id' => $id])->all()
                );
            }

            return $alert;
        });

        $alert->load('departments');
        $alert->recipients()->chunkById(500, function ($users) use ($alert) {
            Notification::send($users, new SafetyAlertNotification($alert));
        });

        return $alert;
    }

    public function acknowledge(SafetyAlert $alert, User $user): void
    {
        $alert->acknowledgements()->firstOrCreate(['user_id' => $user->id], ['acknowledged_at' => now()]);
    }
}
