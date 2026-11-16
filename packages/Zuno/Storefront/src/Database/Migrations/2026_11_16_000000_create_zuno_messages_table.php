<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Messages and feedback sent from the shop's Contact page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zuno_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->nullable();
            $table->string('contact', 120)->nullable(); // phone or email to reply to
            $table->text('message');
            $table->unsignedInteger('customer_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zuno_messages');
    }
};
