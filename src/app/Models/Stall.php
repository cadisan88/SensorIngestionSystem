<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code'])]
class Stall extends Model
{
    // The stalls table has created_at but no updated_at.
    const UPDATED_AT = null;

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class);
    }

    public function parkingSessions(): HasMany
    {
        return $this->hasMany(ParkingSession::class);
    }
}
