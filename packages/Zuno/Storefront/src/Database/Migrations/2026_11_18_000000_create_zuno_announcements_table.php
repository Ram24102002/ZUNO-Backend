<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The sentences of the shop's live bar (the thin strip above the navbar), edited under "Live bar". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zuno_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('text', 160);
            $table->string('link', 255)->nullable(); // e.g. /design; optional
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Start with what the bar said before it became editable
        $now = now();
        DB::table('zuno_announcements')->insert([
            ['text' => 'Free shipping on orders over ₹999', 'link' => null, 'sort' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['text' => 'Design your own tee — try the studio', 'link' => '/design', 'sort' => 2, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['text' => 'New drops every week', 'link' => null, 'sort' => 3, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('zuno_announcements');
    }
};
