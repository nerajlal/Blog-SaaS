<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
        Schema::table('blogs', function (Blueprint $table) {
            $table->index(['project_id', 'status', 'published_at']);
        });
        Schema::table('blog_sections', function (Blueprint $table) {
            $table->index(['blog_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::table('blog_sections', fn (Blueprint $table) => $table->dropIndex(['blog_id', 'order']));
        Schema::table('blogs', fn (Blueprint $table) => $table->dropIndex(['project_id', 'status', 'published_at']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropForeign(['workspace_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['current_workspace_id']));
    }
};
