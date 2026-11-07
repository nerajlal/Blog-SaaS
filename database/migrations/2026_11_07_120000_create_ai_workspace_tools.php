<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('data');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('ai_profile_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->text('data');
            $t->timestamps();
            $t->unique(['project_id', 'revision']);
        });
        Schema::create('ai_settings', function (Blueprint $t) {
            $t->id();
            $t->string('scope')->unique();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('monthly_limit')->default(20);
            $t->timestamps();
        });
        Schema::create('ai_usage_months', function (Blueprint $t) {
            $t->id();
            $t->string('scope');
            $t->string('month', 7);
            $t->unsignedInteger('requests')->default(0);
            $t->unique(['scope', 'month']);
        });
        Schema::create('ai_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_version_id')->nullable()->constrained('ai_profile_versions')->cascadeOnDelete();
            $t->foreignId('blog_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('output_blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            $t->string('scope');
            $t->string('action', 30);
            $t->string('platform', 20)->nullable();
            $t->uuid('request_key')->unique();
            $t->string('fingerprint', 64);
            $t->string('state', 20)->default('pending');
            $t->longText('input');
            $t->longText('result')->nullable();
            $t->longText('edited')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('reviewed_at')->nullable();
            $t->string('model')->nullable();
            $t->string('request_id')->nullable();
            $t->json('usage')->nullable();
            $t->string('detail', 500)->nullable();
            $t->timestamps();
            $t->index(['project_id', 'created_at']);
            $t->index(['scope', 'state']);
        });
    }

    public function down(): void
    {
        foreach (['ai_runs', 'ai_usage_months', 'ai_settings', 'ai_profile_versions', 'ai_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
