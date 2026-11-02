<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stall_id')->constrained()->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->enum('status', ['active', 'completed'])->default('active');

            // The same OCCUPIED event delivered twice cannot create two sessions.
            $table->unique(['stall_id', 'started_at']);

            // Fast lookup of the active session of a stall.
            $table->index(['stall_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_sessions');
    }
};
