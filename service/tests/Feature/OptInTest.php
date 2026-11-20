<?php

namespace Tests\Feature;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MockPlatform;
use Tests\TestCase;

class OptInTest extends TestCase
{
    public function test_opt_in_activates_subscriber_and_sends_welcome_sms(): void
    {
        $response = $this->postJson('/api/subscriptions', ['msisdn' => 'tel:1000000001']);

        $response->assertOk()
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('alreadyActive', false)
            ->assertJsonPath('subscriberId', 'tel:1000000001')
            ->assertJsonPath('welcomeSms.status', 'SENT');

        $this->assertDatabaseHas('subscribers', [
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
            'status' => SubscriberStatus::Active->value,
        ]);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['subscriptionRequests']);
        $this->assertSame('1', $state['subscriptionRequests'][0]['action']);
        $this->assertSame('tel:1000000001', $state['subscriptionRequests'][0]['subscriberId']);
        $this->assertCount(1, $state['sms']);
        $this->assertStringContainsString('Welcome to Daily Pass', $state['sms'][0]['message']);
    }

    public function test_already_active_subscriber_is_not_subscribed_twice(): void
    {
        $this->postJson('/api/subscriptions', ['msisdn' => 'tel:1000000001'])->assertOk();
        $second = $this->postJson('/api/subscriptions', ['msisdn' => 'tel:1000000001']);

        $second->assertOk()
            ->assertJsonPath('alreadyActive', true)
            ->assertJsonPath('welcomeSms', null);

        $state = MockPlatform::state();
        $this->assertCount(1, $state['subscriptionRequests']);
        $this->assertCount(1, $state['sms']);
    }

    public function test_masked_alias_is_used_as_opaque_subscriber_id(): void
    {
        config(['billing.aliases' => ['tel:1000000001' => 'tel:masked-alias-0001']]);

        $this->postJson('/api/subscriptions', ['msisdn' => 'tel:1000000001'])
            ->assertOk()
            ->assertJsonPath('subscriberId', 'tel:masked-alias-0001');

        $state = MockPlatform::state();
        $this->assertSame('tel:masked-alias-0001', $state['subscriptionRequests'][0]['subscriberId']);
        $this->assertSame('tel:1000000001', $state['sms'][0]['to']);
        $this->assertDatabaseHas('subscribers', [
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:masked-alias-0001',
        ]);
    }

    public static function invalidMsisdns(): array
    {
        return [
            ['1000000001'],
            ['tel:+1000000001'],
            ['tel:abc'],
            ['tel:12345'],
            ['tel:1234567890123456'],
            ['tel:12 34'],
        ];
    }

    #[DataProvider('invalidMsisdns')]
    public function test_invalid_msisdn_is_rejected_at_boundary(string $msisdn): void
    {
        $this->postJson('/api/subscriptions', ['msisdn' => $msisdn])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['msisdn']);

        $this->assertDatabaseCount('subscribers', 0);
        $this->assertCount(0, MockPlatform::state()['subscriptionRequests']);
    }

    public function test_carrier_rejection_does_not_activate_subscriber(): void
    {
        MockPlatform::configure(['optinStatus' => 'E1308']);

        $this->postJson('/api/subscriptions', ['msisdn' => 'tel:1000000001'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'carrier_rejected')
            ->assertJsonPath('statusCode', 'E1308');

        $this->assertDatabaseHas('subscribers', [
            'msisdn' => 'tel:1000000001',
            'status' => SubscriberStatus::Inactive->value,
        ]);
        $this->assertCount(0, MockPlatform::state()['sms']);
    }

    public function test_explicit_opt_in_reactivates_subscriber_after_stop(): void
    {
        $subscriber = Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
            'status' => SubscriberStatus::Inactive,
            'activated_at' => null,
            'stopped_at' => now()->subHour(),
            'opt_out_status' => 'CONFIRMED',
        ]);

        $this->postJson('/api/subscriptions', ['msisdn' => $subscriber->msisdn])
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('alreadyActive', false);

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Active, $subscriber->status);
        $this->assertNull($subscriber->stopped_at);
    }
}
