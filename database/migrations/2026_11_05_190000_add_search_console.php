<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('origin', 255);
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->text('property')->nullable();
            $t->string('state', 30)->default('connected');
            $t->string('health', 255)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('search_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('connection_id')->constrained('search_connections')->cascadeOnDelete();
            $t->text('property');
            $t->string('origin', 255);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('state', 30)->default('pending');
            $t->string('detail', 255)->nullable();
            $t->json('rows')->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'created_at']);
        });
        Schema::create('search_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('connection_id')->constrained('search_connections')->cascadeOnDelete();
            $t->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $t->text('url');
            $t->text('property');
            $t->string('state', 30)->default('pending');
            $t->json('result')->nullable();
            $t->string('detail', 255)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_inspections');
        Schema::dropIfExists('search_reports');
        Schema::dropIfExists('search_connections');
    }
};
