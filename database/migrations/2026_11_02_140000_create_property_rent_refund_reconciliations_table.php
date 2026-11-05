<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_rent_refund_reconciliations')) {
            $tableName = 'property_rent_refund_reconciliations';

            if (\Illuminate\Support\Facades\DB::table($tableName)->count() !== 0) {
                throw new \RuntimeException(
                    'STOP: Existing reconciliation table is not empty.'
                );
            }

            $indexes = \Illuminate\Support\Facades\DB::select(
                'SHOW INDEX FROM property_rent_refund_reconciliations'
            );

            $indexNames = array_unique(
                array_map(
                    fn ($index) => $index->Key_name,
                    $indexes
                )
            );

            if ($indexNames !== ['PRIMARY']) {
                throw new \RuntimeException(
                    'STOP: Existing reconciliation table has unexpected indexes.'
                );
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unique(
                    'property_rent_refund_id',
                    'rent_refund_reconcile_unique'
                );

                $table->foreign(
                    'property_rent_refund_id',
                    'rent_refund_reconcile_refund_fk'
                )
                    ->references('id')
                    ->on('property_rent_refunds')
                    ->restrictOnDelete();

                $table->foreign(
                    'evidence_id',
                    'rent_refund_reconcile_evidence_fk'
                )
                    ->references('id')
                    ->on('property_rent_refund_evidences')
                    ->restrictOnDelete();

                $table->foreign(
                    'verified_by',
                    'rent_refund_reconcile_verifier_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'confirmation_source',
                        'provider_transaction_reference',
                    ],
                    'rent_refund_provider_ref_unique'
                );
            });

            return;
        }

        Schema::create(
            'property_rent_refund_reconciliations',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('property_rent_refund_id')
                    ->unique('rent_refund_reconcile_unique');

                $table->foreign(
                    'property_rent_refund_id',
                    'rent_refund_reconcile_refund_fk'
                )
                    ->references('id')
                    ->on('property_rent_refunds')
                    ->restrictOnDelete();

                $table->foreignId('evidence_id');

                $table->foreign(
                    'evidence_id',
                    'rent_refund_reconcile_evidence_fk'
                )
                    ->references('id')
                    ->on('property_rent_refund_evidences')
                    ->restrictOnDelete();

                $table->string('confirmation_source', 100);

                $table->string(
                    'provider_transaction_reference',
                    255
                );

                $table->decimal('confirmed_amount', 18, 2);
                $table->string('currency', 10);

                $table->string('outcome', 30);

                $table->foreignId('verified_by');

                $table->foreign(
                    'verified_by',
                    'rent_refund_reconcile_verifier_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->timestamp('verified_at');

                $table->text('verification_notes')->nullable();

                $table->timestamps();

                $table->unique(
                    [
                        'confirmation_source',
                        'provider_transaction_reference',
                    ],
                    'rent_refund_provider_ref_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_rent_refund_reconciliations'
        );
    }
};
