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
        Schema::table('accounts', function (Blueprint $table) {
            // A group is a non-postable header whose balance rolls up from its children
            // (decision #13). Explicit flag, not implicit "has children": a leaf with no
            // children must stay postable, and a freshly created group has none yet.
            $table->boolean('is_group')->default(false)->after('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_group');
        });
    }
};
