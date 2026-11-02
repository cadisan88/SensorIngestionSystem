<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sensor_uid', 'stall_id'])]
class Sensor extends Model
{
    // The sensors table has created_at but no updated_at.
    const UPDATED_AT = null;

    public function stall(): BelongsTo
    {
        return $this->belongsTo(Stall::class);
    }
}
