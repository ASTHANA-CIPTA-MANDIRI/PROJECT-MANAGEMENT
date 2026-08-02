<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#3b82f6');
            $table->timestamps();
        });

        Schema::create('label_ticket', function (Blueprint $table) {
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->primary(['label_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_ticket');
        Schema::dropIfExists('labels');
    }
};
