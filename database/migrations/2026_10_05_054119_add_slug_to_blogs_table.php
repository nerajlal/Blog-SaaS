<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('title');
        });

        // Backfill existing blogs
        $blogs = DB::table('blogs')->get();
        foreach ($blogs as $blog) {
            $slug = Str::slug($blog->title);
            $originalSlug = $slug;
            $count = 1;
            
            // Check for existing slug in the loop to handle duplicates during backfill
            while(DB::table('blogs')->where('slug', $slug)->where('id', '!=', $blog->id)->exists()) {
                $slug = "{$originalSlug}-{$count}";
                $count++;
            }
            
            DB::table('blogs')->where('id', $blog->id)->update(['slug' => $slug]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
