<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'telemetry_key_hash')) {
            Schema::table('projects', fn (Blueprint $table) => $table->char('telemetry_key_hash', 64)->nullable()->unique());
        }
        if (!Schema::hasTable('seo_link_runs')) Schema::create('seo_link_runs', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('context_hash', 64); $table->string('status', 20)->default('building');
            $table->unsignedBigInteger('cursor')->default(0); $table->unsignedBigInteger('max_blog_id')->default(0);
            $table->unsignedInteger('processed')->default(0); $table->unsignedInteger('omitted')->default(0);
            $table->timestamps(); $table->index(['project_id', 'status']);
        });
        if (!Schema::hasTable('seo_links')) Schema::create('seo_links', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('seo_link_runs')->cascadeOnDelete();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            $table->text('url'); $table->char('url_hash', 64); $table->string('kind', 20);
            $table->string('anchor', 255)->nullable(); $table->unsignedInteger('occurrences')->default(1);
            $table->unique(['run_id', 'blog_id', 'url_hash']); $table->index(['project_id', 'run_id', 'target_blog_id']);
        });
        if (!Schema::hasTable('seo_link_checks')) Schema::create('seo_link_checks', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('url_hash', 64); $table->text('url'); $table->string('state', 30)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable(); $table->text('final_url')->nullable();
            $table->string('detail', 500)->nullable(); $table->char('token', 36);
            $table->timestamp('checked_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'url_hash']);
        });
        if (!Schema::hasTable('seo_link_edits')) Schema::create('seo_link_edits', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('blog_sections')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('section_hash', 64); $table->text('target_url'); $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });
        if (!Schema::hasTable('seo_redirects')) Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 255); $table->char('origin_hash', 64); $table->char('source_key', 64);
            $table->text('source_path'); $table->text('target_path')->nullable();
            $table->unsignedSmallInteger('status_code'); $table->boolean('enabled')->default(true);
            $table->timestamps(); $table->unique(['project_id', 'origin_hash', 'source_key']);
        });
        if (!Schema::hasTable('seo_change_batches')) Schema::create('seo_change_batches', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); $table->string('state', 20)->default('preview');
            $table->char('context_hash', 64); $table->json('changes'); $table->text('reason');
            $table->timestamp('expires_at')->nullable(); $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable(); $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); $table->index(['project_id', 'state']);
        });
        if (!Schema::hasTable('seo_404_events')) Schema::create('seo_404_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('origin_hash', 64); $table->char('path_hash', 64); $table->text('path');
            $table->date('day'); $table->unsignedBigInteger('hits')->default(0);
            $table->text('referrer')->nullable(); $table->timestamp('last_seen')->nullable();
            $table->unique(['project_id', 'origin_hash', 'path_hash', 'day']);
        });
        if (!Schema::hasTable('seo_404_receipts')) Schema::create('seo_404_receipts', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id'); $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'event_id']);
        });
    }

    public function down(): void
    {
        foreach (['seo_404_receipts', 'seo_404_events', 'seo_change_batches', 'seo_redirects', 'seo_link_edits', 'seo_link_checks', 'seo_links', 'seo_link_runs'] as $table) Schema::dropIfExists($table);
        Schema::table('projects', function (Blueprint $table) { $table->dropUnique(['telemetry_key_hash']); $table->dropColumn('telemetry_key_hash'); });
    }
};
