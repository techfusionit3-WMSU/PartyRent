<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\RentalRequest;
use App\Models\RequestMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Customer <-> shop chat on a rental request, without a problem case. */
class RequestChatTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_customer_and_shop_chat_on_a_request(): void
    {
        $r = RentalRequest::where('status', 'pending')->firstOrFail();
        $customer = $r->customer;
        $owner = User::find($r->supplier->owner_id);

        // customer asks a question (JSON from the page)
        $this->actingAs($customer)->withSession(['mode' => 'customer'])
            ->postJson('/rentals/'.$r->code.'/messages', ['body' => 'Can you deliver before 8 AM?'])
            ->assertOk()->assertJsonPath('messages.0.body', 'Can you deliver before 8 AM?')->assertJsonPath('messages.0.mine', true);
        $this->assertSame(1, AppNotification::where('user_id', $owner->id)->where('scope', 'supplier')->where('title', 'New message on '.$r->code)->count());

        // a second message right away does not send a second alert
        $this->actingAs($customer)->postJson('/rentals/'.$r->code.'/messages', ['body' => 'Also, do you have white covers?'])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $owner->id)->where('title', 'New message on '.$r->code)->count());

        // the shop sees both, with an unread count, and answers (plain form post works too)
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get('/supplier/requests/'.$r->code)
            ->assertOk()->assertSee('Can you deliver before 8 AM?')->assertSee('2 new');
        $this->actingAs($owner)->post('/supplier/requests/'.$r->code.'/messages', ['body' => 'Yes, 7 AM works.'])->assertRedirect();
        $this->assertSame('supplier', RequestMessage::latest('id')->first()->side);
        $this->assertSame(1, AppNotification::where('user_id', $customer->id)->where('scope', 'customer')->where('title', 'like', 'New message from%')->count());

        // polling returns only newer messages
        $first = RequestMessage::orderBy('id')->first()->id;
        $this->actingAs($customer)->withSession(['mode' => 'customer'])->getJson('/rentals/'.$r->code.'/messages?after='.$first)
            ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.body', 'Yes, 7 AM works.');
    }

    public function test_only_the_two_sides_can_read_or_post(): void
    {
        $r = RentalRequest::where('status', 'pending')->firstOrFail();
        $stranger = User::where('email', 'bea@demo.ph')->firstOrFail();
        $this->assertNotSame($stranger->id, $r->customer_id);

        $this->actingAs($stranger)->withSession(['mode' => 'customer'])->getJson('/rentals/'.$r->code.'/messages')->assertNotFound();
        $this->actingAs($stranger)->postJson('/rentals/'.$r->code.'/messages', ['body' => 'hi'])->assertNotFound();
        $this->assertSame(0, RequestMessage::count());

        // empty messages are refused
        $this->actingAs($r->customer)->postJson('/rentals/'.$r->code.'/messages', ['body' => '   '])->assertStatus(422);
    }
}
