<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->default(0)->after('role_label');
            $table->index(['envelope_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('signers', function (Blueprint $table): void {
            $table->dropIndex(['envelope_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
