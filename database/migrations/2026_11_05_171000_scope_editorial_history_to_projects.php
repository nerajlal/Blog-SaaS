<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['seo_analyses', 'seo_reviews'] as $name) {
            if (!Schema::hasColumn($name, 'project_id')) {
                Schema::table($name, function (Blueprint $table) {
                    // Unclassified older history stays private until a new scoped report is captured.
                    $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
                    $table->index(['blog_id', 'project_id']);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['seo_reviews', 'seo_analyses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['blog_id', 'project_id']);
                $table->dropForeign(['project_id']);
                $table->dropColumn('project_id');
            });
        }
    }
};
