<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // When it happened (date) vs created_at = when it was entered.
            $table->date('date');
            $table->string('payee')->nullable();
            $table->string('memo')->nullable();
            // expense · income · transfer — cosmetic hint, never affects ledger math.
            $table->string('kind');
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
