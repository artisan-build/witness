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
        Schema::table('mail_deliveries', function (Blueprint $table): void {
            $table->dropForeign('mail_deliveries_signer_id_envelope_id_foreign');
            $table->unsignedBigInteger('signer_id')->nullable()->change();
            $table->string('recipient_kind', 16)->nullable()->after('signer_id');
            $table->string('recipient_name')->nullable()->after('recipient_kind');
            $table->string('recipient_email')->nullable()->after('recipient_name');
            $table->foreign('envelope_id')->references('id')->on('envelopes')->cascadeOnDelete();
            $table->foreign('signer_id')->references('id')->on('signers')->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE mail_deliveries
                SET recipient_kind = 'signer',
                    recipient_name = signers.name,
                    recipient_email = signers.email
                FROM signers
                WHERE signers.id = mail_deliveries.signer_id
                SQL);
        } else {
            DB::table('mail_deliveries')->orderBy('id')->each(function (object $delivery): void {
                $signer = DB::table('signers')->find($delivery->signer_id);
                DB::table('mail_deliveries')->where('id', $delivery->id)->update([
                    'recipient_kind' => 'signer',
                    'recipient_name' => $signer->name,
                    'recipient_email' => $signer->email,
                ]);
            });
        }

        Schema::table('mail_deliveries', function (Blueprint $table): void {
            $table->string('recipient_kind', 16)->nullable(false)->change();
            $table->string('recipient_name')->nullable(false)->change();
            $table->string('recipient_email')->nullable(false)->change();
            $table->unique(
                ['envelope_id', 'purpose', 'recipient_kind', 'recipient_email', 'generation'],
                'mail_deliveries_recipient_generation_unique',
            );
        });
    }

    public function down(): void
    {
        DB::table('mail_deliveries')->whereNull('signer_id')->delete();

        Schema::table('mail_deliveries', function (Blueprint $table): void {
            $table->dropUnique('mail_deliveries_recipient_generation_unique');
            $table->dropForeign(['envelope_id']);
            $table->dropForeign(['signer_id']);
            $table->dropColumn(['recipient_kind', 'recipient_name', 'recipient_email']);
            $table->unsignedBigInteger('signer_id')->nullable(false)->change();
            $table->foreign(['signer_id', 'envelope_id'])
                ->references(['id', 'envelope_id'])
                ->on('signers')
                ->cascadeOnDelete();
        });
    }
};
