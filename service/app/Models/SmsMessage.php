<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscriber_id',
    'direction',
    'kind',
    'address',
    'message',
    'status',
    'status_code',
    'response_payload',
    'sent_at',
])]
class SmsMessage extends Model
{
    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
