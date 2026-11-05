<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL may commit before a later statement fails. Resume without dropping data.
        if (!Schema::hasColumn('projects', 'seo_content_version')) {
            Schema::table('projects', fn (Blueprint $table) => $table->unsignedBigInteger('seo_content_version')->default(0));
        }
        if (!Schema::hasColumn('blogs', 'is_cornerstone')) {
            Schema::table('blogs', fn (Blueprint $table) => $table->boolean('is_cornerstone')->default(false));
        }
        if (!Schema::hasColumn('blogs', 'content_reviewed_at')) {
            Schema::table('blogs', fn (Blueprint $table) => $table->timestamp('content_reviewed_at')->nullable());
        }
        foreach (['seo_title_key', 'seo_focus_key'] as $column) {
            if (!Schema::hasColumn('blogs', $column)) {
                Schema::table('blogs', fn (Blueprint $table) => $table->char($column, 64)->nullable());
            }
            if (!Schema::hasIndex('blogs', ['project_id', $column])) {
                Schema::table('blogs', fn (Blueprint $table) => $table->index(['project_id', $column]));
            }
        }
        if (!Schema::hasTable('seo_analyses')) {
            Schema::create('seo_analyses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
                $table->string('rules_version', 40);
                $table->char('input_hash', 64);
                $table->char('context_hash', 64);
                $table->boolean('missing_metadata')->default(false);
                $table->timestamp('valid_until')->nullable();
                $table->json('result');
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['blog_id', 'rules_version', 'input_hash']);
                $table->index(['blog_id', 'context_hash']);
            });
        }
        if (!Schema::hasTable('seo_reviews')) {
            Schema::create('seo_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('rule_id', 60);
                $table->string('rules_version', 40);
                $table->char('input_hash', 64);
                $table->string('action', 20);
                $table->text('reason');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['blog_id', 'input_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_reviews');
        Schema::dropIfExists('seo_analyses');
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'seo_title_key']);
            $table->dropIndex(['project_id', 'seo_focus_key']);
            $table->dropColumn(['is_cornerstone', 'content_reviewed_at', 'seo_title_key', 'seo_focus_key']);
        });
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('seo_content_version'));
    }
};
