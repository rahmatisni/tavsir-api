<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransOrder extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('trans_order', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_id');
            $table->string('order_type');
            $table->string('consume_type')->comment('dine_in or take_away')->nullable();
            $table->string('nomor_name')->comment('nomor meja atau nama customer')->nullable();
            $table->integer('sub_total')->unsigned();
            $table->integer('fee')->unsigned()->default(0);
            $table->integer('service_fee')->unsigned()->default(0);
            $table->integer('total')->unsigned();
            $table->date('pickup_date')->nullable();
            $table->date('confirm_date')->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->string('rating_comment')->nullable();
            $table->integer('business_id')->unsigned()->nullable();
            $table->integer('rest_area_id')->unsigned()->nullable();
            $table->integer('tenant_id')->unsigned()->nullable();
            $table->integer('supertenant_id')->unsigned()->nullable();
            $table->integer('merchant_id')->unsigned()->nullable();
            $table->integer('sub_merchant_id')->unsigned()->nullable();
            $table->string('paystation_id')->nullable();
            $table->string('customer_id')->nullable()->comment('customer_id from id user travoy');
            $table->string('customer_name')->nullable()->comment('customer_name from id user travoy');
            $table->string('customer_phone')->nullable()->comment('customer_phone from id user travoy');
            $table->integer('payment_method_id')->unsigned()->nullable();
            $table->integer('payment_id')->unsigned()->nullable();
            $table->integer('discount')->unsigned()->nullable();
            $table->string('casheer_id')->nullable();
            $table->integer('pay_amount')->unsigned()->nullable();
            $table->string('code_verif')->nullable();
            $table->string('status')->default('PENDING');
            $table->boolean('is_refund')->default(0);
            $table->string('canceled_by')->nullable();
            $table->string('canceled_name')->nullable();
            $table->string('reason_cancel')->nullable();
            $table->integer('voucher_id')->nullable();
            $table->integer('id_ops')->nullable();
            $table->integer('saldo_qr')->unisgned()->nullable();
            $table->string('description')->nullable();
            $table->integer('harga_kios')->nullable();
            $table->date('settlement_at')->nullable();
            $table->integer('addon_total')->unsigned()->default(0);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('trans_order_detil', function (Blueprint $table) {
            $table->increments('id');
            $table->string('trans_order_id');
            $table->integer('product_id')->unsigned();
            $table->string('product_name');
            $table->text('customize')->nullable();
            $table->integer('base_price')->unsigned();
            $table->integer('price')->unsigned();
            $table->tinyInteger('qty')->unsigned();
            $table->integer('total_price')->unsigned();
            $table->string('note')->nullable();
            $table->string('status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('trans_order_detil');
        Schema::dropIfExists('trans_order');
    }
}
