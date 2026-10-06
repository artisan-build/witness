<?php

declare(strict_types=1);

use App\Enums\MailDeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('envelope_id');
            $table->unsignedBigInteger('signer_id');
            $table->string('purpose', 32);
            $table->unsignedInteger('generation');
            $table->string('status', 16)->default(MailDeliveryStatus::Pending->value)->index();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('unknown_at')->nullable();
            $table->timestamps();
            $table->unique(['signer_id', 'purpose', 'generation']);
            $table->foreign(['signer_id', 'envelope_id'])
                ->references(['id', 'envelope_id'])
                ->on('signers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
