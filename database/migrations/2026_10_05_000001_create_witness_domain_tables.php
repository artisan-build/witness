<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->unsignedBigInteger('audit_event_count')->default(0);
            $table->char('audit_chain_head', 64)->nullable();
            $table->string('created_ip_address', 45)->nullable();
            $table->text('created_user_agent')->nullable();
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
            $table->unique(['id', 'envelope_id']);
        });

        Schema::create('fields', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('envelope_id');
            $table->unsignedBigInteger('signer_id');
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
            $table->foreign(['signer_id', 'envelope_id'])
                ->references(['id', 'envelope_id'])
                ->on('signers')
                ->cascadeOnDelete();
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
            $table->unique(['id', 'template_id']);
        });

        Schema::create('template_fields', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('template_role_id');
            $table->unsignedSmallInteger('page');
            $table->string('type', 24);
            $table->boolean('required')->default(true);
            $table->decimal('position_x', 12, 10);
            $table->decimal('position_y', 12, 10);
            $table->decimal('width', 12, 10);
            $table->decimal('height', 12, 10);
            $table->timestamps();
            $table->index(['template_id', 'page']);
            $table->foreign(['template_role_id', 'template_id'])
                ->references(['id', 'template_id'])
                ->on('template_roles')
                ->cascadeOnDelete();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->restrictOnDelete();
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

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->installPostgresGuards();
        }
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

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP FUNCTION IF EXISTS witness_guard_envelopes();
                DROP FUNCTION IF EXISTS witness_guard_documents();
                DROP FUNCTION IF EXISTS witness_guard_signers();
                DROP FUNCTION IF EXISTS witness_guard_fields();
                DROP FUNCTION IF EXISTS witness_guard_templates();
                DROP FUNCTION IF EXISTS witness_guard_template_roles();
                DROP FUNCTION IF EXISTS witness_guard_template_fields();
                SQL);
        }
    }

    private function installPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION witness_guard_envelopes() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.team_id IS DISTINCT FROM OLD.team_id OR NEW.sender_id IS DISTINCT FROM OLD.sender_id THEN
                        RAISE EXCEPTION 'Envelope team and sender are immutable';
                    END IF;

                    IF NEW.status IS DISTINCT FROM OLD.status
                        AND current_setting('witness.envelope_transition', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'Envelope status may only change through a lifecycle action';
                    END IF;

                    IF (NEW.audit_event_count IS DISTINCT FROM OLD.audit_event_count
                        OR NEW.audit_chain_head IS DISTINCT FROM OLD.audit_chain_head)
                        AND current_setting('witness.audit_append', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'Envelope audit anchors may only be advanced by the audit chain';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'Only draft envelopes may be deleted without the destructive domain action';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_envelopes_guard
            BEFORE UPDATE OR DELETE ON envelopes
            FOR EACH ROW EXECUTE FUNCTION witness_guard_envelopes();

            CREATE OR REPLACE FUNCTION witness_guard_documents() RETURNS trigger AS $$
            DECLARE
                envelope_status text;
                target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;

                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Documents can only be attached to draft envelopes';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN
                        RAISE EXCEPTION 'Document envelope identity is immutable';
                    END IF;

                    IF envelope_status <> 'draft' AND ROW(
                        NEW.original_name, NEW.storage_path, NEW.mime_type, NEW.size_bytes,
                        NEW.page_count, NEW.original_sha256
                    ) IS DISTINCT FROM ROW(
                        OLD.original_name, OLD.storage_path, OLD.mime_type, OLD.size_bytes,
                        OLD.page_count, OLD.original_sha256
                    ) THEN
                        RAISE EXCEPTION 'Document source is frozen after send';
                    END IF;

                    IF ROW(NEW.final_storage_path, NEW.final_sha256) IS DISTINCT FROM ROW(OLD.final_storage_path, OLD.final_sha256)
                        AND current_setting('witness.final_document_write', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'Final document artifacts require the locked domain action';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN
                    RAISE EXCEPTION 'Documents cannot be removed after send';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_documents_guard
            BEFORE INSERT OR UPDATE OR DELETE ON documents
            FOR EACH ROW EXECUTE FUNCTION witness_guard_documents();

            CREATE OR REPLACE FUNCTION witness_guard_signers() RETURNS trigger AS $$
            DECLARE
                envelope_status text;
            BEGIN
                SELECT status INTO envelope_status
                FROM envelopes
                WHERE id = COALESCE(NEW.envelope_id, OLD.envelope_id)
                FOR UPDATE;

                IF TG_OP = 'UPDATE' AND NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN
                    RAISE EXCEPTION 'Signer envelope identity is immutable';
                END IF;

                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN
                    RAISE EXCEPTION 'Signers cannot be removed after send';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_signers_guard
            BEFORE UPDATE OR DELETE ON signers
            FOR EACH ROW EXECUTE FUNCTION witness_guard_signers();

            CREATE OR REPLACE FUNCTION witness_guard_fields() RETURNS trigger AS $$
            DECLARE
                envelope_status text;
                target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;

                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Fields can only be added to draft envelopes';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id OR NEW.signer_id IS DISTINCT FROM OLD.signer_id THEN
                        RAISE EXCEPTION 'Field container identity is immutable';
                    END IF;

                    IF envelope_status <> 'draft' AND ROW(
                        NEW.page, NEW.type, NEW.required, NEW.position_x, NEW.position_y, NEW.width, NEW.height
                    ) IS DISTINCT FROM ROW(
                        OLD.page, OLD.type, OLD.required, OLD.position_x, OLD.position_y, OLD.width, OLD.height
                    ) THEN
                        RAISE EXCEPTION 'Field definition is frozen after send';
                    END IF;

                    IF (NEW.value::text IS DISTINCT FROM OLD.value::text
                        OR NEW.completed_at IS DISTINCT FROM OLD.completed_at)
                        AND current_setting('witness.field_response_write', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'Field responses require the locked domain action';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN
                    RAISE EXCEPTION 'Fields cannot be removed after send';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_fields_guard
            BEFORE INSERT OR UPDATE OR DELETE ON fields
            FOR EACH ROW EXECUTE FUNCTION witness_guard_fields();

            CREATE OR REPLACE FUNCTION witness_guard_templates() RETURNS trigger AS $$
            BEGIN
                IF NEW.team_id IS DISTINCT FROM OLD.team_id OR NEW.sender_id IS DISTINCT FROM OLD.sender_id THEN
                    RAISE EXCEPTION 'Template team and sender are immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_templates_guard
            BEFORE UPDATE ON templates
            FOR EACH ROW EXECUTE FUNCTION witness_guard_templates();

            CREATE OR REPLACE FUNCTION witness_guard_template_roles() RETURNS trigger AS $$
            BEGIN
                IF NEW.template_id IS DISTINCT FROM OLD.template_id THEN
                    RAISE EXCEPTION 'Template role parent is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_template_roles_guard
            BEFORE UPDATE ON template_roles
            FOR EACH ROW EXECUTE FUNCTION witness_guard_template_roles();

            CREATE OR REPLACE FUNCTION witness_guard_template_fields() RETURNS trigger AS $$
            BEGIN
                IF NEW.template_id IS DISTINCT FROM OLD.template_id
                    OR NEW.template_role_id IS DISTINCT FROM OLD.template_role_id THEN
                    RAISE EXCEPTION 'Template field container identity is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER witness_template_fields_guard
            BEFORE UPDATE ON template_fields
            FOR EACH ROW EXECUTE FUNCTION witness_guard_template_fields();
            SQL);
    }
};
