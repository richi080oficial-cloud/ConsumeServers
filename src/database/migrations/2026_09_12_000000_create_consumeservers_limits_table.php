<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumeservers_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->enum('metric', ['cpu', 'memory', 'network', 'uptime']);
            $table->enum('period', ['instant', 'daily', 'monthly'])->default('instant');
            $table->unsignedBigInteger('threshold_value');
            $table->enum('action', ['stop', 'suspend'])->default('stop');
            $table->boolean('notify_admin')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->index(['server_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumeservers_limits');
    }
};
