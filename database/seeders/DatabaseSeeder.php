<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Project;
use App\Models\Category;
use App\Models\Blog;
use App\Models\BlogSection;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Demo SaaS User
        $user = User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
            ]
        );

        // 2. Create Default Workspace
        $workspace = Workspace::updateOrCreate(
            ['slug' => 'demo-hq'],
            [
                'owner_id' => $user->id,
                'name' => 'Demo HQ Workspace',
            ]
        );

        // Attach user to workspace as owner
        if (!$workspace->users()->where('users.id', $user->id)->exists()) {
            $workspace->users()->attach($user->id, ['role' => 'owner']);
        }

        // Set as active workspace
        $user->current_workspace_id = $workspace->id;
        $user->save();

        // 3. Create Sample Project
        $project = Project::updateOrCreate(
            ['subdomain' => 'tech-insights'],
            [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'name' => 'Tech Insights Blog',
                'description' => 'A technology publication covering AI, web development, and cloud computing.',
                'api_key' => 'pk_demo_' . Str::random(24),
            ]
        );

        // 4. Create Categories
        $categories = [
            'Technology' => 'Articles about modern web tech, stacks and AI',
            'Tutorials' => 'Step-by-step guides for developers',
            'Product Updates' => 'Release notes and new feature announcements',
        ];

        $categoryModels = [];
        foreach ($categories as $name => $desc) {
            $categoryModels[$name] = Category::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'slug' => Str::slug($name),
                ],
                [
                    'name' => $name,
                ]
            );
        }

        // 5. Create Sample Blog Post 1
        $blog1 = Blog::updateOrCreate(
            [
                'project_id' => $project->id,
                'slug' => 'welcome-to-blog-saas',
            ],
            [
                'user_id' => $user->id,
                'title' => 'Welcome to the Next-Gen SaaS Blog Engine',
                'excerpt' => 'An introduction to our multi-tenant headless content management engine.',
                'content' => 'Modern development teams and businesses require isolated environments to run content hubs, document APIs, and distribute stories directly into their frontends.',
                'category_id' => $categoryModels['Product Updates']->id,
                'status' => 'published',
                'author_name' => 'Demo Admin',
                'author_title' => 'Lead Architect',
                'published_at' => now(),
            ]
        );

        // Clear and add sections for Blog 1
        $blog1->sections()->delete();
        $blog1->sections()->createMany([
            [
                'order' => 1,
                'text_content' => 'Modern development teams and businesses require isolated environments to run content hubs, document APIs, and distribute stories directly into their frontends.',
            ],
            [
                'order' => 2,
                'text_content' => 'With our built-in REST API, consuming published posts into Next.js, Nuxt, Astro, or mobile apps takes just one GET request with your project API token.',
            ],
        ]);

        // 6. Create Sample Blog Post 2
        $blog2 = Blog::updateOrCreate(
            [
                'project_id' => $project->id,
                'slug' => 'getting-started-with-headless-architecture',
            ],
            [
                'user_id' => $user->id,
                'title' => 'Getting Started with Headless Content Architecture',
                'excerpt' => 'Learn how to query blog posts and sections using REST APIs.',
                'content' => 'Learn how to connect your Next.js or Astro frontend using our REST API tokens to render your blog with near-zero latency.',
                'category_id' => $categoryModels['Tutorials']->id,
                'status' => 'published',
                'author_name' => 'Demo Admin',
                'author_title' => 'Jamstack Developer',
                'published_at' => now()->subDay(),
            ]
        );

        $blog2->sections()->delete();
        $blog2->sections()->createMany([
            [
                'order' => 1,
                'text_content' => 'Head over to your project settings inside the dashboard and copy your Project API Key (starts with pk_).',
            ],
            [
                'order' => 2,
                'text_content' => 'Send a GET request to /api/v1/projects/blogs with your X-API-KEY header to receive live published articles, categories, and structured sections.',
            ],
        ]);
    }
}
