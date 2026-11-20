<?php

namespace App\Models;

use App\Enums\SubscriberStatus;
use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'msisdn',
    'subscriber_id',
    'status',
    'activated_at',
    'stopped_at',
    'suspended_at',
    'opt_in_requested_at',
    'opt_out_status',
    'opt_out_attempts',
    'opt_out_confirmed_at',
    'last_webhook_at',
])]
class Subscriber extends Model
{
    /** @use HasFactory<SubscriberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SubscriberStatus::class,
            'activated_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'opt_in_requested_at' => 'immutable_datetime',
            'opt_out_confirmed_at' => 'immutable_datetime',
            'last_webhook_at' => 'immutable_datetime',
        ];
    }

    public function chargeOperations(): HasMany
    {
        return $this->hasMany(ChargeOperation::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriberStatus::Active && $this->stopped_at === null;
    }

    public function hasStopped(): bool
    {
        return $this->stopped_at !== null;
    }
}
