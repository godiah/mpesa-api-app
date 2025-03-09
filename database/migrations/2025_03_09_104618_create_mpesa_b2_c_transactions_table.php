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
        Schema::create('mpesa_b2_c_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('originator_conversation_id')->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('command_id');
            $table->string('initiator_name');
            $table->string('phone_number');
            $table->decimal('amount', 10, 2);
            $table->string('result_code')->nullable();
            $table->string('result_description')->nullable();
            $table->text('remarks');
            $table->text('occasion')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('request_data')->nullable();
            $table->json('result_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_b2_c_transactions');
    }
};
