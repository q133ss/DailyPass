<?php

namespace App\Models;

use App\Enums\ChargeOperationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'subscriber_id',
    'billing_day',
    'external_trx_id',
    'amount',
    'currency',
    'status',
    'attempts_count',
    'next_retry_at',
    'reference_id',
    'internal_trx_id',
    'last_status_code',
    'last_status_detail',
    'receipt_sent_at',
    'resolved_at',
])]
class ChargeOperation extends Model
{
    protected function casts(): array
    {
        return [
            'billing_day' => 'immutable_date',
            'amount' => 'decimal:2',
            'status' => ChargeOperationStatus::class,
            'next_retry_at' => 'immutable_datetime',
            'receipt_sent_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ChargeAttempt::class)->orderBy('attempt_no');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
