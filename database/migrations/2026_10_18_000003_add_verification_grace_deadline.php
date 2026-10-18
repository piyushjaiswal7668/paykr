<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->timestamp('verification_grace_expires_at', 3)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropIndex(['verification_grace_expires_at']);
            $table->dropColumn('verification_grace_expires_at');
        });
    }
};
