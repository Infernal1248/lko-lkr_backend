<?php

namespace Tests\Feature;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_sees_account_identity_and_user_receives_support_answer(): void
    {
        $owner = User::factory()->create(['username' => 'client_login']);
        $other = User::factory()->create();
        $admin = User::factory()->create(['is_support_admin' => true]);
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Принтер']);
        $this->actingAs($admin)->postJson('/api/support/'.$ticket->id.'/messages', ['body' => 'Ответ поддержки'])->assertCreated();
        $this->getJson('/api/support')->assertJsonPath('data.0.user.username', 'client_login');
        $this->getJson('/api/support/'.$ticket->id)->assertJsonPath('ticket.user.email', $owner->email);
        $this->actingAs($owner)->getJson('/api/support/unread')->assertJsonPath('count', 1);
        $this->getJson('/api/support')->assertJsonPath('data.0.has_unread_reply', true)->assertJsonMissingPath('data.0.user');
        $updated = $ticket->fresh()->updated_at->toISOString();
        $this->getJson('/api/support/'.$ticket->id)->assertOk()->assertJsonPath('messages.data.0.body', 'Ответ поддержки')->assertJsonPath('ticket.has_unread_reply', false)->assertJsonMissingPath('ticket.user');
        $this->assertSame($updated, $ticket->fresh()->updated_at->toISOString());
        $this->getJson('/api/support/unread')->assertJsonPath('count', 0);
        $this->actingAs($admin)->postJson('/api/support/'.$ticket->id.'/messages', ['body' => 'Новый ответ'])->assertCreated();
        $this->actingAs($owner)->getJson('/api/support/unread')->assertJsonPath('count', 1);
        $this->actingAs($other)->getJson('/api/support/unread')->assertJsonPath('count', 0);
        $this->getJson('/api/support/'.$ticket->id)->assertNotFound();
    }

    public function test_default_page_contains_latest_reply_and_old_page_does_not_mark_it_read(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_support_admin' => true]);
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Длинная переписка']);
        for ($i = 0; $i < 50; $i++) {
            SupportMessage::create(['ticket_id' => $ticket->id, 'author_id' => $owner->id, 'from_support' => false, 'body' => 'Вопрос '.$i]);
        }
        SupportMessage::create(['ticket_id' => $ticket->id, 'author_id' => $admin->id, 'from_support' => true, 'body' => 'Последний ответ']);
        $this->actingAs($owner)->getJson('/api/support/'.$ticket->id.'?page=1')->assertOk()->assertJsonPath('messages.current_page', 1);
        $this->getJson('/api/support/unread')->assertJsonPath('count', 1);
        $this->getJson('/api/support/'.$ticket->id)->assertOk()->assertJsonPath('messages.current_page', 2)->assertJsonPath('messages.data.0.body', 'Последний ответ');
        $this->getJson('/api/support/unread')->assertJsonPath('count', 0);
    }
}
