<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_retries', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->nullable();
            $table->string('method');
            $table->string('uri');
            $table->json('headers')->nullable();
            $table->json('body')->nullable();
            $table->json('files')->nullable();
            $table->json('tags')->nullable();
            $table->integer('retries_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->integer('retry_delay')->default(0); 
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_retries');
    }
};
