<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('seo_settings')->nullable();
            $table->string('seo_domain_token', 64)->nullable();
            $table->timestamp('seo_verified_at')->nullable();
        });
        Schema::table('blogs', function (Blueprint $table) {
            $table->json('seo_settings')->nullable();
            $table->string('cover_image_alt', 500)->nullable();
            $table->text('cover_image_caption')->nullable();
            $table->boolean('cover_image_decorative')->default(false);
        });
        Schema::table('blog_sections', function (Blueprint $table) {
            $table->string('image_alt', 500)->nullable();
            $table->text('image_caption')->nullable();
            $table->boolean('image_decorative')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('blog_sections', fn (Blueprint $table) => $table->dropColumn(['image_alt', 'image_caption', 'image_decorative']));
        Schema::table('blogs', fn (Blueprint $table) => $table->dropColumn(['seo_settings', 'cover_image_alt', 'cover_image_caption', 'cover_image_decorative']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['seo_settings', 'seo_domain_token', 'seo_verified_at']));
    }
};
