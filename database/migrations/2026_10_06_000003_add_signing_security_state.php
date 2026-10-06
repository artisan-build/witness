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
        Schema::table('signers', function (Blueprint $table): void {
            $table->timestampTz('otp_sent_at')->nullable()->after('otp_hash');
            $table->timestampTz('otp_verified_at')->nullable()->after('otp_locked_at');
            $table->timestampTz('consented_at')->nullable()->after('viewed_at');
            $table->timestampTz('declined_at')->nullable()->after('signed_at');
            $table->text('decline_reason')->nullable()->after('declined_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
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

                    IF ROW(
                        NEW.status, NEW.link_token_hash, NEW.otp_hash, NEW.otp_sent_at,
                        NEW.otp_expires_at, NEW.otp_attempts, NEW.otp_locked_at,
                        NEW.otp_verified_at, NEW.viewed_at, NEW.consented_at,
                        NEW.signed_at, NEW.declined_at, NEW.decline_reason
                    ) IS DISTINCT FROM ROW(
                        OLD.status, OLD.link_token_hash, OLD.otp_hash, OLD.otp_sent_at,
                        OLD.otp_expires_at, OLD.otp_attempts, OLD.otp_locked_at,
                        OLD.otp_verified_at, OLD.viewed_at, OLD.consented_at,
                        OLD.signed_at, OLD.declined_at, OLD.decline_reason
                    ) AND current_setting('witness.signer_progress_write', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'Signer progress requires the locked domain action';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' AND envelope_status IS NOT NULL AND envelope_status <> 'draft' THEN
                    RAISE EXCEPTION 'Signers cannot be removed after send';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS witness_signers_guard ON signers;
                DROP FUNCTION IF EXISTS witness_guard_signers();
                SQL);
        }

        Schema::table('signers', function (Blueprint $table): void {
            $table->dropColumn([
                'otp_sent_at',
                'otp_verified_at',
                'consented_at',
                'declined_at',
                'decline_reason',
            ]);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
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

                CREATE TRIGGER witness_signers_guard
                BEFORE INSERT OR UPDATE OR DELETE ON signers
                FOR EACH ROW EXECUTE FUNCTION witness_guard_signers();
                SQL);
        }
    }
};
