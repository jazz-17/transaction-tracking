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
            // No stored base_amount and no stored rate — every base/rate figure is derived on
            // read (decision #4). A cross-currency transaction is two observed amounts; its rate,
            // when a report or the deviation guard needs it, is −B/F over the money legs (#11/#16).
            $table->string('memo')->nullable();
            $table->timestamps();

            // Balance(account) = Σ amount per currency, scoped by user (decision #5/#15).
            $table->index('account_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
