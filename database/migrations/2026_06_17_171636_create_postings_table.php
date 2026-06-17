<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            // Hard delete of a transaction cascades its postings (decision #8).
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            // Denormalized owner so scoped balance/report aggregates need no join (decision #7).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Signed integer minor units in the posting's own currency.
            $table->bigInteger('amount');
            $table->string('currency', 3);
            // Signed integer minor units in the user's base currency; Σ base_amount = 0 per txn.
            $table->bigInteger('base_amount');
            $table->string('memo')->nullable();
            $table->timestamps();

            // Balance(account) = Σ amount; net worth = Σ base_amount scoped by user.
            $table->index('account_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
