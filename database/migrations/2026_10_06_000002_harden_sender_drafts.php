<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION witness_guard_envelopes() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.team_id IS DISTINCT FROM OLD.team_id OR NEW.sender_id IS DISTINCT FROM OLD.sender_id THEN
                        RAISE EXCEPTION 'Envelope team and sender are immutable';
                    END IF;

                    IF OLD.status <> 'draft' AND ROW(NEW.title, NEW.message, NEW.expires_at)
                        IS DISTINCT FROM ROW(OLD.title, OLD.message, OLD.expires_at) THEN
                        RAISE EXCEPTION 'Envelope details are frozen after send';
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

            CREATE OR REPLACE FUNCTION witness_guard_signers() RETURNS trigger AS $$
            DECLARE
                envelope_status text;
            BEGIN
                SELECT status INTO envelope_status
                FROM envelopes
                WHERE id = COALESCE(NEW.envelope_id, OLD.envelope_id)
                FOR UPDATE;

                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Signers can only be added to draft envelopes';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN
                        RAISE EXCEPTION 'Signer envelope identity is immutable';
                    END IF;

                    IF envelope_status <> 'draft' AND ROW(NEW.name, NEW.email, NEW.role_label, NEW.position)
                        IS DISTINCT FROM ROW(OLD.name, OLD.email, OLD.role_label, OLD.position) THEN
                        RAISE EXCEPTION 'Signer definitions are frozen after send';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN
                    RAISE EXCEPTION 'Signers cannot be removed after send';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS witness_signers_guard ON signers;
            CREATE TRIGGER witness_signers_guard
            BEFORE INSERT OR UPDATE OR DELETE ON signers
            FOR EACH ROW EXECUTE FUNCTION witness_guard_signers();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_file_deletions');

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

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

            CREATE OR REPLACE FUNCTION witness_guard_signers() RETURNS trigger AS $$
            DECLARE envelope_status text;
            BEGIN
                SELECT status INTO envelope_status FROM envelopes
                WHERE id = COALESCE(NEW.envelope_id, OLD.envelope_id) FOR UPDATE;
                IF TG_OP = 'UPDATE' AND NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN
                    RAISE EXCEPTION 'Signer envelope identity is immutable';
                END IF;
                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN
                    RAISE EXCEPTION 'Signers cannot be removed after send';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS witness_signers_guard ON signers;
            CREATE TRIGGER witness_signers_guard
            BEFORE UPDATE OR DELETE ON signers
            FOR EACH ROW EXECUTE FUNCTION witness_guard_signers();
            SQL);
    }
};
