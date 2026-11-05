<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'property_rent_refund_reconciliations',
            function (Blueprint $table) {
                $table->string(
                    'confirmed_recipient',
                    255
                )->nullable();

                $table->timestamp(
                    'provider_confirmed_at'
                )->nullable();
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'property_rent_refund_reconciliations',
            function (Blueprint $table) {
                $table->dropColumn([
                    'confirmed_recipient',
                    'provider_confirmed_at',
                ]);
            }
        );
    }
};
