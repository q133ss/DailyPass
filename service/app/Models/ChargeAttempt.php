<?php

namespace App\Models;

use App\Enums\ChargeAttemptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'charge_operation_id',
    'attempt_no',
    'external_trx_id',
    'status',
    'status_code',
    'status_detail',
    'reference_id',
    'internal_trx_id',
    'started_at',
    'finished_at',
    'response_payload',
    'error',
])]
class ChargeAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ChargeAttemptStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'response_payload' => 'array',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(ChargeOperation::class, 'charge_operation_id');
    }
}
