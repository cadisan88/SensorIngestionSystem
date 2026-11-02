<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sensor_uid',
    'stall_id',
    'event_type',
    'battery_level_pct',
    'temperature_c',
    'signal_rssi_dbm',
    'recorded_at',
    'received_at',
])]
class SensorReading extends Model
{
    // The sensor_readings table has no created_at or updated_at.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'battery_level_pct' => 'decimal:2',
            'temperature_c' => 'decimal:2',
            'signal_rssi_dbm' => 'integer',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    // Empty when the sensor is not mapped to a stall.
    public function stall(): BelongsTo
    {
        return $this->belongsTo(Stall::class);
    }
}
