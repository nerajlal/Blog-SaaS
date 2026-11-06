<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('blog_autosaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('base_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->longText('snapshot');
            $table->timestamps();
            $table->unique(['project_id', 'blog_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_autosaves');
    }
};
