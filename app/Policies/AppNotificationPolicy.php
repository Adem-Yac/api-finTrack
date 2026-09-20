<?php

namespace App\Policies;

use App\Models\AppNotification;
use App\Models\User;

class AppNotificationPolicy
{
    public function update(User $user, AppNotification $notification): bool
    {
        return $user->id === $notification->user_id;
    }
}
