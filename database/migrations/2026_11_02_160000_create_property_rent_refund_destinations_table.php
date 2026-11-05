<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_rent_refund_destinations',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('property_rent_refund_id')
                    ->unique('rent_refund_destination_unique');

                $table->foreign(
                    'property_rent_refund_id',
                    'rent_refund_dest_refund_fk'
                )
                    ->references('id')
                    ->on('property_rent_refunds')
                    ->restrictOnDelete();

                $table->string('refund_method', 30);

                $table->string('recipient_name', 255);

                // Store sensitive identifiers encrypted
                // through the future Eloquent model.
                $table->text('recipient_identifier');

                $table->string('provider_name', 150);

                $table->string('status', 30)
                    ->default('pending');

                $table->foreignId('recorded_by');

                $table->foreign(
                    'recorded_by',
                    'rent_refund_dest_recorder_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreignId('approved_by')->nullable();

                $table->foreign(
                    'approved_by',
                    'rent_refund_dest_approver_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->timestamp('approved_at')->nullable();

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_rent_refund_destinations'
        );
    }
};
