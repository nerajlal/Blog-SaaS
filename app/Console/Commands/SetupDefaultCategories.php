<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use Illuminate\Support\Str;

class SetupDefaultCategories extends Command
{
    protected $signature = 'setup:categories';
    protected $description = 'Setup default categories for existing projects and backfill blogs';

    public function handle()
    {
        $defaultCategories = ['General', 'News', 'Tutorials'];

        $projects = Project::all();
        $this->info("Found {$projects->count()} projects.");

        foreach ($projects as $project) {
            $categories = [];
            foreach ($defaultCategories as $catName) {
                $categories[$catName] = $project->categories()->firstOrCreate([
                    'name' => $catName,
                ], [
                    'slug' => Str::slug($catName),
                ]);
            }

            $generalCatId = $categories['General']->id;

            // Update blogs in this project that have no category
            $updated = $project->blogs()->whereNull('category_id')->update([
                'category_id' => $generalCatId
            ]);

            $this->info("Project [{$project->name}] - Created defaults, updated $updated blogs.");
        }

        $this->info('Done setting up categories.');
    }
}
