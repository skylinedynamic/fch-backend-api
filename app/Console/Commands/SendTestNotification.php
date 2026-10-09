<?php

namespace App\Console\Commands;

use App\Models\User\User;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Console\Command;

/**
 * QA check for in-app notifications: sends the `system.test` notification to
 * one user. It should appear in that user's bell immediately when the queue
 * worker and Reverb are running, and on the next fetch otherwise.
 */
class SendTestNotification extends Command
{
    protected $signature = 'notifications:test {user : The user id to notify}';

    protected $description = 'Send a test in-app notification to a user (checks queue + Reverb + bell)';

    public function handle(Notifier $notifier): int
    {
        $user = User::find($this->argument('user'));

        if (!$user) {
            $this->error('User not found.');
            return self::FAILURE;
        }

        $notifier->send('system.test', $user);
        $this->info("Test notification queued for user #{$user->id}.");

        return self::SUCCESS;
    }
}
