<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_hash',
    'subscriber_id',
    'status',
    'event_timestamp',
    'payload',
    'processed_at',
])]
class PlatformEvent extends Model
{
    protected function casts(): array
    {
        return [
            'event_timestamp' => 'immutable_datetime',
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
