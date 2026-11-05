<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Blog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    public function create(Project $project)
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }
        $categories = $project->categories;
        return view('admin.create-blog', compact('project', 'categories'));
    }

    public function store(Request $request, Project $project)
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'banner_image' => 'nullable|image|max:2048',
            'image_credits' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'author_title' => 'nullable|string|max:255',
            'author_avatar' => 'nullable|image|max:2048',
        ]);

        $coverImagePath = null;
        if ($request->hasFile('banner_image')) {
            $coverImagePath = $request->file('banner_image')->store('blogs/banners', 'public');
        }

        $authorAvatarPath = null;
        if ($request->hasFile('author_avatar')) {
            $authorAvatarPath = $request->file('author_avatar')->store('blogs/avatars', 'public');
        }

        $status = $request->input('status', 'draft');
        $published_at = null;
        
        if ($status === 'published') {
            $published_at = now();
        } elseif ($status === 'scheduled') {
            $status = 'published';
            $published_at = $request->input('published_at');
        }

        // Generate unique slug
        $slug = Str::slug($request->title);
        $originalSlug = $slug;
        $count = 1;
        while (Blog::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        $blog = Blog::create([
            'project_id' => $project->id,
            'user_id' => Auth::id(),
            'category_id' => $request->category_id,
            'title' => $request->title,
            'slug' => $slug,
            'content' => '', // Content is now stored in sections
            'cover_image' => $coverImagePath ? '/storage/' . $coverImagePath : null,
            'status' => $status,
            'published_at' => $published_at,
            'image_credits' => $request->image_credits,
            'author_name' => $request->author_name,
            'author_title' => $request->author_title,
            'author_avatar' => $authorAvatarPath ? '/storage/' . $authorAvatarPath : null,
        ]);

        if ($request->has('sections') && is_array($request->sections)) {
            foreach ($request->sections as $index => $sectionData) {
                $sectionImagePath = null;
                
                // We will handle images differently since they are files.
                // When using array syntax for files, Laravel structures them nicely.
                if (isset($sectionData['image']) && $sectionData['image'] instanceof \Illuminate\Http\UploadedFile) {
                    $path = $sectionData['image']->store('blogs/sections', 'public');
                    $sectionImagePath = '/storage/' . $path;
                }

                $blog->sections()->create([
                    'text_content' => $sectionData['text'] ?? '',
                    'image_path' => $sectionImagePath,
                    'order' => $index,
                ]);
            }
        }

        return redirect()->route('admin.project-details', $project->id);
    }

    public function edit(Blog $blog)
    {
        if ($blog->user_id !== Auth::id()) {
            abort(403);
        }
        $project = $blog->project;
        $categories = $project->categories;
        return view('admin.edit-blog', compact('blog', 'project', 'categories'));
    }

    public function update(Request $request, Blog $blog)
    {
        if ($blog->user_id !== Auth::id()) {
            abort(403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'banner_image' => 'nullable|image|max:2048',
            'image_credits' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'author_title' => 'nullable|string|max:255',
            'author_avatar' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('banner_image')) {
            $coverImagePath = $request->file('banner_image')->store('blogs/banners', 'public');
            $blog->cover_image = '/storage/' . $coverImagePath;
        }

        if ($request->hasFile('author_avatar')) {
            $authorAvatarPath = $request->file('author_avatar')->store('blogs/avatars', 'public');
            $blog->author_avatar = '/storage/' . $authorAvatarPath;
        }

        $status = $request->input('status', 'draft');
        $published_at = null;
        
        if ($status === 'published') {
            $published_at = $blog->published_at && $blog->published_at <= now() ? $blog->published_at : now();
        } elseif ($status === 'scheduled') {
            $status = 'published';
            $published_at = $request->input('published_at');
        }

        // Generate unique slug
        $slug = Str::slug($request->title);
        $originalSlug = $slug;
        $count = 1;
        while (Blog::where('slug', $slug)->where('id', '!=', $blog->id)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        $blog->update([
            'title' => $request->title,
            'slug' => $slug,
            'category_id' => $request->category_id,
            'status' => $status,
            'published_at' => $published_at,
            'image_credits' => $request->image_credits,
            'author_name' => $request->author_name,
            'author_title' => $request->author_title,
        ]);

        // Delete old sections and recreate to handle ordering and dynamic inputs
        $blog->sections()->delete();

        if ($request->has('sections') && is_array($request->sections)) {
            foreach ($request->sections as $index => $sectionData) {
                $sectionImagePath = $sectionData['existing_image'] ?? null;
                
                if (isset($sectionData['image']) && $sectionData['image'] instanceof \Illuminate\Http\UploadedFile) {
                    $path = $sectionData['image']->store('blogs/sections', 'public');
                    $sectionImagePath = '/storage/' . $path;
                }

                $blog->sections()->create([
                    'text_content' => $sectionData['text'] ?? '',
                    'image_path' => $sectionImagePath,
                    'order' => $index,
                ]);
            }
        }

        return redirect()->route('admin.project-details', $blog->project_id);
    }

    public function toggleStatus(Blog $blog)
    {
        if ($blog->user_id !== Auth::id()) {
            abort(403);
        }

        $blog->status = $blog->status === 'published' ? 'draft' : 'published';
        $blog->save();

        return redirect()->back();
    }

    public function destroy(Blog $blog)
    {
        if ($blog->user_id !== Auth::id()) {
            abort(403);
        }
        
        $projectId = $blog->project_id;
        $blog->delete();

        return redirect()->route('admin.project-details', $projectId);
    }

    public function move(Request $request, Blog $blog)
    {
        if ($blog->user_id !== Auth::id()) {
            abort(403);
        }

        $request->validate([
            'target_project_id' => 'required|exists:projects,id',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $targetProject = Auth::user()->projects()->findOrFail($request->target_project_id);

        if ($targetProject->id === $blog->project_id) {
            return redirect()->back()->with('error', 'Blog is already in this project.');
        }

        $sourceProjectId = $blog->project_id;

        $categoryId = $request->category_id;
        if ($categoryId) {
            $belongsToTarget = $targetProject->categories()->where('id', $categoryId)->exists();
            if (!$belongsToTarget) {
                $categoryId = null;
            }
        }

        // If no valid category provided, check if target project has a category with the same name
        if (!$categoryId && $blog->category) {
            $matchedCategory = $targetProject->categories()->where('name', $blog->category->name)->first();
            if ($matchedCategory) {
                $categoryId = $matchedCategory->id;
            }
        }

        $blog->update([
            'project_id' => $targetProject->id,
            'category_id' => $categoryId,
        ]);

        $targetUrl = route('admin.project-details', $targetProject->id);
        return redirect()->route('admin.project-details', $sourceProjectId)
            ->with('success', "Blog '<strong>" . e($blog->title) . "</strong>' was successfully moved to <a href='{$targetUrl}' class='underline font-bold hover:text-emerald-950'>{$targetProject->name}</a>.");
    }
}
