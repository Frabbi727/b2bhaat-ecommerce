<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panels' notification bell filters on `data->>'format'`. On PostgreSQL that only works when `notifications.data`
 * is a JSON column; as text, every admin and seller page failed with a 500.
 */
class DatabaseNotificationsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_notification_bell_query_runs(): void
    {
        $user = User::factory()->create();

        Notification::make()->title('Product approved')->sendToDatabase($user);

        $this->assertSame(1, $user->unreadNotifications()->where('data->format', 'filament')->count());
    }
}
