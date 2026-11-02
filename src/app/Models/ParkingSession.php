<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stall_id', 'started_at', 'ended_at', 'duration_minutes', 'status'])]
class ParkingSession extends Model
{
    // The parking_sessions table has no created_at or updated_at.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function stall(): BelongsTo
    {
        return $this->belongsTo(Stall::class);
    }
}
