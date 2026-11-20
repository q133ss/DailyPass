<?php

namespace Tests\Feature;

use App\Enums\SubscriberStatus;
use App\Models\PlatformEvent;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SubscriptionWebhookTest extends TestCase
{
    public function test_registered_activates_known_inactive_subscriber(): void
    {
        $subscriber = Subscriber::factory()->inactive()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);

        $this->webhook(['status' => 'REGISTERED'])
            ->assertOk()
            ->assertJson(['statusCode' => 'S1000', 'statusDetail' => 'Success']);

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Active, $subscriber->status);
        $this->assertDatabaseCount('platform_events', 1);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $subscriber = Subscriber::factory()->inactive()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);

        $payload = $this->webhook(['status' => 'REGISTERED']);
        $this->webhook(['status' => 'REGISTERED'])->assertOk();

        $payload->assertOk();
        $this->assertSame(1, PlatformEvent::count());
        $this->assertSame(SubscriberStatus::Active, $subscriber->refresh()->status);
    }

    public function test_unregistered_deactivates_and_stops_subscriber(): void
    {
        $subscriber = Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);

        $this->webhook(['status' => 'UNREGISTERED'])->assertOk();

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->status);
        $this->assertNotNull($subscriber->stopped_at);
        $this->assertSame('CONFIRMED', $subscriber->opt_out_status);
    }

    public function test_late_registered_after_stop_does_not_reactivate_subscriber(): void
    {
        $subscriber = Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);

        $this->webhook([
            'status' => 'UNREGISTERED',
            'timeStamp' => '2026-11-20T11:59:00+00:00',
        ])->assertOk();

        $this->webhook([
            'status' => 'REGISTERED',
            'timeStamp' => '2026-11-20T12:01:00+00:00',
        ])->assertOk();

        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->status);
        $this->assertNotNull($subscriber->stopped_at);
        $this->assertSame(2, PlatformEvent::count());
    }

    public function test_registered_after_explicit_new_opt_in_is_allowed(): void
    {
        $subscriber = Subscriber::factory()->create([
            'msisdn' => 'tel:1000000001',
            'subscriber_id' => 'tel:1000000001',
        ]);

        $this->webhook(['status' => 'UNREGISTERED'])->assertOk();
        $subscriber->refresh();
        $this->assertSame(SubscriberStatus::Inactive, $subscriber->status);

        // Explicit opt-in sets opt_in_requested_at after stopped_at and
        // activates immediately.
        $this->postJson('/api/subscriptions', ['msisdn' => $subscriber->msisdn])->assertOk();

        $this->webhook([
            'status' => 'REGISTERED',
            'timeStamp' => CarbonImmutable::now()->addMinute()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(SubscriberStatus::Active, $subscriber->refresh()->status);
    }

    public function test_unknown_subscriber_is_acknowledged_without_creating_state(): void
    {
        $this->webhook(['subscriberId' => 'tel:9999999999', 'status' => 'REGISTERED'])->assertOk();

        $this->assertDatabaseCount('subscribers', 0);
        $this->assertDatabaseCount('platform_events', 1);
    }

    public function test_wrong_application_id_is_rejected(): void
    {
        $this->webhook(['applicationId' => 'APP_WRONG'])
            ->assertUnauthorized()
            ->assertJsonPath('statusCode', 'E1001');

        $this->assertDatabaseCount('platform_events', 0);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->webhook(['status' => 'SOMETHING_ELSE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** @param array<string, mixed> $overrides */
    private function webhook(array $overrides = [])
    {
        return $this->postJson('/api/webhooks/subscription', array_merge([
            'applicationId' => 'APP_000123',
            'subscriberId' => 'tel:1000000001',
            'frequency' => 'DAILY',
            'status' => 'REGISTERED',
            'version' => '1.0',
            'timeStamp' => '2026-11-20T11:00:00+00:00',
        ], $overrides));
    }
}
