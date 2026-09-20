<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_and_mark_notifications_read(): void
    {
        $user = User::factory()->create();
        $item = AppNotification::query()->create([
            'user_id' => $user->id,
            'title' => 'Budget divertissement',
            'body' => 'Vous avez utilisé 98% de votre enveloppe.',
            'type' => 'warning',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.id', $item->id);

        $read = $this->postJson('/api/v1/notifications/'.$item->id.'/read')->assertOk();
        $this->assertNotNull($read->json('data.read_at'));

        $this->getJson('/api/v1/notifications')
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_user_cannot_mark_someone_elses_notification(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = AppNotification::query()->create([
            'user_id' => $owner->id,
            'title' => 'Privé',
            'body' => 'Ne pas lire.',
            'type' => 'info',
        ]);

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/notifications/'.$item->id.'/read')
            ->assertForbidden();
    }
}
