<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // asset · liability · income · expense · equity
            $table->string('type');
            // Native ISO 4217 currency; non-null only for asset/liability (Model A).
            $table->string('currency', 3)->nullable();
            // Hierarchy + grouping; column present in v1, flat UI for now.
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
