<?php

namespace Database\Seeders;

use App\Models\Sensor;
use App\Models\Stall;
use App\Services\SensorStallResolver;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo data: stall code => sensor_uid.
     * The README test event uses sensor-99f4a1.
     */
    private const DEMO_STALLS = [
        'A-01' => 'sensor-99f4a1',
        'A-02' => 'sensor-99f4a2',
        'A-03' => 'sensor-99f4a3',
        'A-04' => 'sensor-99f4a4',
        'A-05' => 'sensor-99f4a5',
    ];

    /**
     * Safe to run more than once. It does not create duplicates.
     */
    public function run(SensorStallResolver $resolver): void
    {
        foreach (self::DEMO_STALLS as $code => $sensorUid) {
            $stall = Stall::firstOrCreate(['code' => $code]);

            Sensor::updateOrCreate(
                ['sensor_uid' => $sensorUid],
                ['stall_id' => $stall->id],
            );

            // This code changes a mapping, so it must clear the cached values.
            // The workers will read the new mapping from the database.
            $resolver->forget($sensorUid);
        }
    }
}
