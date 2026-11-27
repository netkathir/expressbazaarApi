<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_banners')) {
            return;
        }

        Schema::create('vendor_banners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->unique();
            $table->string('eyebrow', 40)->nullable();          // small label above the heading
            $table->string('heading', 80);                       // main heading (line breaks allowed)
            $table->string('highlight', 60)->nullable();         // last heading line, shown in green
            $table->string('description', 220)->nullable();
            $table->string('primary_button_text', 30)->default('Shop Now');
            $table->string('primary_button_link', 255)->default('#featured-sections');
            $table->string('image_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_banners');
    }
};
