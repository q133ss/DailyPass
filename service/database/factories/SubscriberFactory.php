<?php

namespace Database\Factories;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    protected $model = Subscriber::class;

    public function definition(): array
    {
        $digits = '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $msisdn = 'tel:'.$digits;

        return [
            'msisdn' => $msisdn,
            'subscriber_id' => $msisdn,
            'status' => SubscriberStatus::Active,
            'activated_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriberStatus::Inactive,
            'activated_at' => null,
        ]);
    }
}
