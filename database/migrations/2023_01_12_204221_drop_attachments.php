<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only restores the column shape, not the data: dropColumn() in up()
     * discards whatever was stored there, and there is no backup to restore
     * from. Expected for a column drop, noted here per audit criteria.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->longText('attachments')->nullable();
        });
    }
};
