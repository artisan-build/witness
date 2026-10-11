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
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reminder_interval_days')->nullable()->default(3);
            $table->timestampTz('last_reminded_at')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION witness_purge_matches(target bigint) RETURNS boolean AS $$
            BEGIN
                RETURN COALESCE(current_setting('witness.purge_envelope', true) = target::text, false);
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_envelopes() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.team_id IS DISTINCT FROM OLD.team_id OR NEW.sender_id IS DISTINCT FROM OLD.sender_id THEN RAISE EXCEPTION 'Envelope team and sender are immutable'; END IF;
                    IF NEW.status IS DISTINCT FROM OLD.status AND current_setting('witness.envelope_transition', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Envelope status may only change through a lifecycle action'; END IF;
                    IF OLD.status <> 'draft' AND ROW(NEW.title, NEW.message, NEW.expires_at, NEW.reminder_interval_days) IS DISTINCT FROM ROW(OLD.title, OLD.message, OLD.expires_at, OLD.reminder_interval_days) THEN RAISE EXCEPTION 'Envelope details are frozen after send'; END IF;
                    IF (NEW.audit_event_count IS DISTINCT FROM OLD.audit_event_count OR NEW.audit_chain_head IS DISTINCT FROM OLD.audit_chain_head) AND current_setting('witness.audit_append', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Envelope audit anchors may only be advanced by the audit chain'; END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status <> 'draft' AND NOT witness_purge_matches(OLD.id) THEN RAISE EXCEPTION 'Only the scoped destructive domain action may delete this envelope'; END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_documents() RETURNS trigger AS $$
            DECLARE envelope_status text; target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;
                IF TG_OP = 'DELETE' THEN
                    IF envelope_status IS NOT NULL AND envelope_status <> 'draft' AND NOT witness_purge_matches(OLD.envelope_id) THEN RAISE EXCEPTION 'Documents cannot be removed after send'; END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Documents can only be attached to draft envelopes'; END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN RAISE EXCEPTION 'Document envelope identity is immutable'; END IF;
                    IF envelope_status <> 'draft' AND ROW(NEW.original_name, NEW.storage_path, NEW.mime_type, NEW.size_bytes, NEW.page_count, NEW.original_sha256) IS DISTINCT FROM ROW(OLD.original_name, OLD.storage_path, OLD.mime_type, OLD.size_bytes, OLD.page_count, OLD.original_sha256) THEN RAISE EXCEPTION 'Document source is frozen after send'; END IF;
                    IF ROW(NEW.final_storage_path, NEW.final_sha256) IS DISTINCT FROM ROW(OLD.final_storage_path, OLD.final_sha256) AND current_setting('witness.final_document_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Final document artifacts require the locked domain action'; END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_signers() RETURNS trigger AS $$
            DECLARE envelope_status text; target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;
                IF TG_OP = 'DELETE' THEN
                    IF envelope_status IS NOT NULL AND envelope_status <> 'draft' AND NOT witness_purge_matches(OLD.envelope_id) THEN RAISE EXCEPTION 'Signers cannot be removed after send'; END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Signers can only be added to draft envelopes'; END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN RAISE EXCEPTION 'Signer envelope identity is immutable'; END IF;
                    IF envelope_status <> 'draft' AND ROW(NEW.name, NEW.email, NEW.role_label, NEW.position) IS DISTINCT FROM ROW(OLD.name, OLD.email, OLD.role_label, OLD.position) THEN RAISE EXCEPTION 'Signer definitions are frozen after send'; END IF;
                    IF ROW(NEW.status, NEW.link_token_hash, NEW.otp_hash, NEW.otp_sent_at, NEW.otp_expires_at, NEW.otp_attempts, NEW.otp_locked_at, NEW.otp_verified_at, NEW.viewed_at, NEW.consented_at, NEW.signed_at, NEW.declined_at, NEW.decline_reason) IS DISTINCT FROM ROW(OLD.status, OLD.link_token_hash, OLD.otp_hash, OLD.otp_sent_at, OLD.otp_expires_at, OLD.otp_attempts, OLD.otp_locked_at, OLD.otp_verified_at, OLD.viewed_at, OLD.consented_at, OLD.signed_at, OLD.declined_at, OLD.decline_reason) AND current_setting('witness.signer_progress_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Signer progress requires the locked domain action'; END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_fields() RETURNS trigger AS $$
            DECLARE envelope_status text; target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;
                IF TG_OP = 'DELETE' THEN
                    IF envelope_status IS NOT NULL AND envelope_status <> 'draft' AND NOT witness_purge_matches(OLD.envelope_id) THEN RAISE EXCEPTION 'Fields cannot be removed after send'; END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Fields can only be added to draft envelopes'; END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id OR NEW.signer_id IS DISTINCT FROM OLD.signer_id THEN RAISE EXCEPTION 'Field container identity is immutable'; END IF;
                    IF envelope_status <> 'draft' AND ROW(NEW.page, NEW.type, NEW.required, NEW.position_x, NEW.position_y, NEW.width, NEW.height) IS DISTINCT FROM ROW(OLD.page, OLD.type, OLD.required, OLD.position_x, OLD.position_y, OLD.width, OLD.height) THEN RAISE EXCEPTION 'Field definition is frozen after send'; END IF;
                    IF (NEW.value::text IS DISTINCT FROM OLD.value::text OR NEW.completed_at IS DISTINCT FROM OLD.completed_at) AND current_setting('witness.field_response_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Field responses require the locked domain action'; END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        Schema::table('envelopes', fn (Blueprint $table) => $table->dropColumn(['reminder_interval_days', 'last_reminded_at']));
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION witness_guard_envelopes() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.team_id IS DISTINCT FROM OLD.team_id OR NEW.sender_id IS DISTINCT FROM OLD.sender_id THEN RAISE EXCEPTION 'Envelope team and sender are immutable'; END IF;
                    IF OLD.status <> 'draft' AND ROW(NEW.title, NEW.message, NEW.expires_at) IS DISTINCT FROM ROW(OLD.title, OLD.message, OLD.expires_at) THEN RAISE EXCEPTION 'Envelope details are frozen after send'; END IF;
                    IF NEW.status IS DISTINCT FROM OLD.status AND current_setting('witness.envelope_transition', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Envelope status may only change through a lifecycle action'; END IF;
                    IF (NEW.audit_event_count IS DISTINCT FROM OLD.audit_event_count OR NEW.audit_chain_head IS DISTINCT FROM OLD.audit_chain_head) AND current_setting('witness.audit_append', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Envelope audit anchors may only be advanced by the audit chain'; END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN RAISE EXCEPTION 'Only draft envelopes may be deleted without the destructive domain action'; END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_documents() RETURNS trigger AS $$
            DECLARE envelope_status text; target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;
                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Documents can only be attached to draft envelopes'; END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN RAISE EXCEPTION 'Document envelope identity is immutable'; END IF;
                    IF envelope_status <> 'draft' AND ROW(NEW.original_name, NEW.storage_path, NEW.mime_type, NEW.size_bytes, NEW.page_count, NEW.original_sha256) IS DISTINCT FROM ROW(OLD.original_name, OLD.storage_path, OLD.mime_type, OLD.size_bytes, OLD.page_count, OLD.original_sha256) THEN RAISE EXCEPTION 'Document source is frozen after send'; END IF;
                    IF ROW(NEW.final_storage_path, NEW.final_sha256) IS DISTINCT FROM ROW(OLD.final_storage_path, OLD.final_sha256) AND current_setting('witness.final_document_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Final document artifacts require the locked domain action'; END IF;
                END IF;
                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN RAISE EXCEPTION 'Documents cannot be removed after send'; END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_signers() RETURNS trigger AS $$
            DECLARE envelope_status text;
            BEGIN
                SELECT status INTO envelope_status
                FROM envelopes
                WHERE id = COALESCE(NEW.envelope_id, OLD.envelope_id)
                FOR UPDATE;
                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Signers can only be added to draft envelopes'; END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN RAISE EXCEPTION 'Signer envelope identity is immutable'; END IF;
                    IF envelope_status <> 'draft' AND ROW(NEW.name, NEW.email, NEW.role_label, NEW.position) IS DISTINCT FROM ROW(OLD.name, OLD.email, OLD.role_label, OLD.position) THEN RAISE EXCEPTION 'Signer definitions are frozen after send'; END IF;
                    IF ROW(NEW.status, NEW.link_token_hash, NEW.otp_hash, NEW.otp_sent_at, NEW.otp_expires_at, NEW.otp_attempts, NEW.otp_locked_at, NEW.otp_verified_at, NEW.viewed_at, NEW.consented_at, NEW.signed_at, NEW.declined_at, NEW.decline_reason) IS DISTINCT FROM ROW(OLD.status, OLD.link_token_hash, OLD.otp_hash, OLD.otp_sent_at, OLD.otp_expires_at, OLD.otp_attempts, OLD.otp_locked_at, OLD.otp_verified_at, OLD.viewed_at, OLD.consented_at, OLD.signed_at, OLD.declined_at, OLD.decline_reason) AND current_setting('witness.signer_progress_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Signer progress requires the locked domain action'; END IF;
                END IF;
                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN RAISE EXCEPTION 'Signers cannot be removed after send'; END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION witness_guard_fields() RETURNS trigger AS $$
            DECLARE envelope_status text; target_envelope_id bigint;
            BEGIN
                target_envelope_id := COALESCE(NEW.envelope_id, OLD.envelope_id);
                SELECT status INTO envelope_status FROM envelopes WHERE id = target_envelope_id FOR UPDATE;
                IF TG_OP = 'INSERT' AND envelope_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Fields can only be added to draft envelopes'; END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.envelope_id IS DISTINCT FROM OLD.envelope_id OR NEW.signer_id IS DISTINCT FROM OLD.signer_id THEN RAISE EXCEPTION 'Field container identity is immutable'; END IF;
                    IF envelope_status <> 'draft' AND ROW(NEW.page, NEW.type, NEW.required, NEW.position_x, NEW.position_y, NEW.width, NEW.height) IS DISTINCT FROM ROW(OLD.page, OLD.type, OLD.required, OLD.position_x, OLD.position_y, OLD.width, OLD.height) THEN RAISE EXCEPTION 'Field definition is frozen after send'; END IF;
                    IF (NEW.value::text IS DISTINCT FROM OLD.value::text OR NEW.completed_at IS DISTINCT FROM OLD.completed_at) AND current_setting('witness.field_response_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Field responses require the locked domain action'; END IF;
                END IF;
                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN RAISE EXCEPTION 'Fields cannot be removed after send'; END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS witness_purge_matches(bigint);
            SQL);
    }
};
