<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumeservers_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->enum('metric', ['cpu', 'memory', 'network', 'uptime']);
            $table->enum('period', ['instant', 'daily', 'monthly']);
            $table->timestamp('period_start');
            $table->unsignedBigInteger('accumulated_value')->default(0);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->unique(['server_id', 'metric', 'period', 'period_start'], 'csul_unique_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumeservers_usage_logs');
    }
};
