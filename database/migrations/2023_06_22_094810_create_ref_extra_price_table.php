<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRefExtraPriceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ref_extra_price', function (Blueprint $table) {
            $table->id();
            $table->integer('tenant_id')->unsigned();
            $table->string('name');
            $table->boolean('is_percent');
            $table->unsignedInteger('price');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ref_extra_price');
    }
}
