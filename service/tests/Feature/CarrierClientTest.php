<?php

namespace Tests\Feature;

use App\Carrier\CarrierPlatformClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class CarrierClientTest extends TestCase
{
    public function test_credentials_are_sent_in_body_but_never_logged(): void
    {
        Log::spy();
        Http::fake([
            'https://carrier.test/*' => Http::response([
                'statusCode' => 'S1000',
                'statusDetail' => 'Request was successfully processed',
                'externalTrxId' => 'T-1',
                'internalTrxId' => '123',
                'referenceId' => '456',
            ]),
        ]);
        config([
            'services.platform.base_url' => 'https://carrier.test',
            'services.platform.application_id' => 'APP_TEST',
            'services.platform.password' => 'unit-test-secret',
        ]);

        $response = app(CarrierPlatformClient::class)->debit('T-1', 'tel:masked', '5.00', 'XTS');

        $this->assertTrue($response->successful());
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://carrier.test/caas/direct/debit'
                && $request['applicationId'] === 'APP_TEST'
                && $request['password'] === 'unit-test-secret'
                && $request['amount'] === '5.00';
        });

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $serialized = $message.' '.json_encode($context);

            return ! str_contains($serialized, 'unit-test-secret');
        });
    }

    public function test_amount_without_two_decimals_is_rejected_before_http(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        try {
            app(CarrierPlatformClient::class)->debit('T-2', 'tel:masked', '5', 'XTS');
        } finally {
            Http::assertNothingSent();
        }
    }
}
