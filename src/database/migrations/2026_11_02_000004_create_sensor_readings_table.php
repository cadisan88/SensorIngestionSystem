<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();

            // No foreign key: readings from unknown sensors are stored too.
            $table->string('sensor_uid', 64);

            // Empty when the sensor is not mapped to a stall.
            $table->foreignId('stall_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('event_type', ['OCCUPIED', 'VACANT', 'HEARTBEAT', 'ERROR']);
            $table->decimal('battery_level_pct', 5, 2)->nullable();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->smallInteger('signal_rssi_dbm')->nullable();

            // recorded_at: time inside the packet. received_at: when the server got it.
            $table->dateTime('recorded_at');
            $table->dateTime('received_at');

            // The same packet delivered twice is stored once.
            $table->unique(['sensor_uid', 'event_type', 'recorded_at'], 'sensor_readings_dedup_unique');

            $table->index(['stall_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};
