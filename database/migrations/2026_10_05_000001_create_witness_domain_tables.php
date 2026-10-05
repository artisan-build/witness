<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['team_id', 'user_id']);
        });

        Schema::create('envelopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('status', 24)->default(EnvelopeStatus::Draft->value)->index();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('page_count');
            $table->char('original_sha256', 64);
            $table->string('final_storage_path')->nullable();
            $table->char('final_sha256', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('signers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('role_label')->nullable();
            $table->string('status', 24)->default(SignerStatus::Pending->value)->index();
            $table->char('link_token_hash', 64)->nullable();
            $table->string('otp_hash')->nullable();
            $table->timestampTz('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestampTz('otp_locked_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestamps();
            $table->unique(['envelope_id', 'email']);
        });

        Schema::create('fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('type', 24);
            $table->boolean('required')->default(true);
            $table->decimal('position_x', 12, 10);
            $table->decimal('position_y', 12, 10);
            $table->decimal('width', 12, 10);
            $table->decimal('height', 12, 10);
            $table->json('value')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['envelope_id', 'page']);
        });

        Schema::create('templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('original_name');
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('page_count');
            $table->char('original_sha256', 64);
            $table->timestamps();
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('template_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['template_id', 'label']);
        });

        Schema::create('template_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('type', 24);
            $table->boolean('required')->default(true);
            $table->decimal('position_x', 12, 10);
            $table->decimal('position_y', 12, 10);
            $table->decimal('width', 12, 10);
            $table->decimal('height', 12, 10);
            $table->timestamps();
            $table->index(['template_id', 'page']);
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('verb_event_id')->unique();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 32);
            $table->timestampTz('occurred_at', precision: 6);
            $table->string('actor_type', 16);
            $table->string('actor_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload');
            $table->text('canonical_event');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['envelope_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('template_fields');
        Schema::dropIfExists('template_roles');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('fields');
        Schema::dropIfExists('signers');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('envelopes');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
