<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_sale_payments', function (Blueprint $table) {
            $table->unique(
                ['payment_method', 'transaction_reference'],
                'sale_payment_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('property_sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payment_external_unique');
        });
    }
};
