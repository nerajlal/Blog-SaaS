<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Blog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\BlogMedia;
use App\Services\Seo\SeoInput;
use App\Services\Seo\ContentAnalyzer;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    public function create(Project $project)
    {
        Gate::authorize('update', $project);
        $categories = $project->categories;
        return view('admin.create-blog', compact('project', 'categories'));
    }

    public function store(Request $request, Project $project)
    {
        Gate::authorize('update', $project);

        $validated = $request->validate(array_merge([
            'title' => 'required|string|max:255',
            'category_id' => ['required', Rule::exists('categories', 'id')->where('project_id', $project->id)],
            'banner_image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
            'image_credits' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'author_title' => 'nullable|string|max:255',
            'author_avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
            'status' => ['required', Rule::in(['draft', 'published', 'scheduled'])],
            'published_at' => 'exclude_unless:status,scheduled|required|date|after:now',
            'sections' => 'required|array|min:1|max:100',
            'sections.*' => 'array',
            'sections.*.text' => 'nullable|string|max:100000',
            'sections.*.image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
            'sections.*.existing_image' => ['nullable', 'string', Rule::in($existingImages ?? [])],
        ], SeoInput::blogRules()));

        return BlogMedia::transaction(function (BlogMedia $media) use ($request, $project, $validated) {
            $coverImagePath = null;
            if ($request->hasFile('banner_image')) {
                $coverImagePath = $media->store($request->file('banner_image'), 'blogs/banners');
            }

            $authorAvatarPath = null;
            if ($request->hasFile('author_avatar')) {
                $authorAvatarPath = $media->store($request->file('author_avatar'), 'blogs/avatars');
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
            $slug = Str::slug($request->title) ?: Str::lower(Str::random(12));
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
                'seo_settings' => array_key_exists('seo', $validated) ? ($validated['seo'] ?? []) : ($blog->seo_settings ?? []),
                'cover_image_alt' => $request->exists('cover_image_alt') ? $request->input('cover_image_alt') : ($blog->cover_image_alt ?? null),
                'cover_image_caption' => $request->exists('cover_image_caption') ? $request->input('cover_image_caption') : ($blog->cover_image_caption ?? null),
                'cover_image_decorative' => $request->exists('cover_image_decorative') ? $request->boolean('cover_image_decorative') : ($blog->cover_image_decorative ?? false),
                'is_cornerstone' => $request->exists('is_cornerstone') ? $request->boolean('is_cornerstone') : ($blog->is_cornerstone ?? false),
                'content_reviewed_at' => now(),
                'image_credits' => $request->image_credits,
                'author_name' => $request->author_name,
                'author_title' => $request->author_title,
                'author_avatar' => $authorAvatarPath ? '/storage/' . $authorAvatarPath : null,
            ]);

            if ($request->has('sections') && is_array($request->sections)) {
                foreach (array_values($request->sections) as $index => $sectionData) {
                    $sectionImagePath = null;

                    // We will handle images differently since they are files.
                    // When using array syntax for files, Laravel structures them nicely.
                    if (isset($sectionData['image']) && $sectionData['image'] instanceof \Illuminate\Http\UploadedFile) {
                        $path = $media->store($sectionData['image'], 'blogs/sections');
                        $sectionImagePath = '/storage/' . $path;
                    }

                    $blog->sections()->create([
                        'text_content' => $sectionData['text'] ?? '',
                        'image_path' => $sectionImagePath,
                        'image_alt' => $sectionData['image_alt'] ?? null,
                        'image_caption' => $sectionData['image_caption'] ?? null,
                        'image_decorative' => filter_var($sectionData['image_decorative'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'order' => $index,
                    ]);
                }
            }

            app(ContentAnalyzer::class)->capture($blog);
            return redirect()->route('admin.project-details', $project->id);
        });
    }

    public function edit(Blog $blog)
    {
        Gate::authorize('update', $blog->project);
        $project = $blog->project;
        $categories = $project->categories;
        $editorRevision = app(\App\Services\Editor\BlogRevision::class)->hash($blog);
        return response()->view('admin.edit-blog', compact('blog', 'project', 'categories', 'editorRevision'))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Blog $blog)
    {
        Gate::authorize('update', $blog->project);
        $project = $blog->project;
        $existingImages = $blog->sections()->pluck('image_path')->filter()->all();

        $validated = $request->validate(array_merge([
            'editor_revision' => 'sometimes|required|string|size:64',
            'autosave_version' => 'sometimes|integer|min:0',
            'title' => 'required|string|max:255',
            'category_id' => ['required', Rule::exists('categories', 'id')->where('project_id', $project->id)],
            'banner_image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
            'image_credits' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'author_title' => 'nullable|string|max:255',
            'author_avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
            'status' => ['required', Rule::in(['draft', 'published', 'scheduled'])],
            'published_at' => 'exclude_unless:status,scheduled|required|date|after:now',
            'sections' => 'required|array|min:1|max:100',
            'sections.*' => 'array',
            'sections.*.text' => 'nullable|string|max:100000',
            'sections.*.image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
            'sections.*.existing_image' => ['nullable', 'string', Rule::in($existingImages ?? [])],
        ], SeoInput::blogRules()));

        return BlogMedia::transaction(function (BlogMedia $media) use ($request, $blog, $validated) {
            $current = Blog::whereKey($blog->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->project_id === $blog->project_id, 409, 'This post moved to another project. Reload before editing.');
            if (isset($validated['editor_revision'])) {
                abort_unless(hash_equals(app(\App\Services\Editor\BlogRevision::class)->hash($current), $validated['editor_revision']), 409, 'This post changed in another tab. Your recovery draft is preserved. Review the latest post before updating.');
            }
            $blog = $current;
            $previousMedia = array_merge([$blog->cover_image, $blog->author_avatar], $blog->sections()->pluck('image_path')->all());
            if ($request->hasFile('banner_image')) {
                $coverImagePath = $media->store($request->file('banner_image'), 'blogs/banners');
                $blog->cover_image = '/storage/' . $coverImagePath;
            }

            if ($request->hasFile('author_avatar')) {
                $authorAvatarPath = $media->store($request->file('author_avatar'), 'blogs/avatars');
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

            $slug = $blog->slug ?: Str::slug($request->title) ?: Str::lower(Str::random(12));

            $blog->update([
                'title' => $request->title,
                'slug' => $slug,
                'category_id' => $request->category_id,
                'status' => $status,
                'published_at' => $published_at,
                'seo_settings' => array_key_exists('seo', $validated) ? ($validated['seo'] ?? []) : ($blog->seo_settings ?? []),
                'cover_image_alt' => $request->exists('cover_image_alt') ? $request->input('cover_image_alt') : ($blog->cover_image_alt ?? null),
                'cover_image_caption' => $request->exists('cover_image_caption') ? $request->input('cover_image_caption') : ($blog->cover_image_caption ?? null),
                'cover_image_decorative' => $request->exists('cover_image_decorative') ? $request->boolean('cover_image_decorative') : ($blog->cover_image_decorative ?? false),
                'is_cornerstone' => $request->exists('is_cornerstone') ? $request->boolean('is_cornerstone') : ($blog->is_cornerstone ?? false),
                'content_reviewed_at' => now(),
                'image_credits' => $request->image_credits,
                'author_name' => $request->author_name,
                'author_title' => $request->author_title,
            ]);

            // Delete old sections and recreate to handle ordering and dynamic inputs
            $blog->sections()->delete();

            if ($request->has('sections') && is_array($request->sections)) {
                foreach (array_values($request->sections) as $index => $sectionData) {
                    $sectionImagePath = $sectionData['existing_image'] ?? null;

                    if (isset($sectionData['image']) && $sectionData['image'] instanceof \Illuminate\Http\UploadedFile) {
                        $path = $media->store($sectionData['image'], 'blogs/sections');
                        $sectionImagePath = '/storage/' . $path;
                    }

                    $blog->sections()->create([
                        'text_content' => $sectionData['text'] ?? '',
                        'image_path' => $sectionImagePath,
                        'image_alt' => $sectionData['image_alt'] ?? null,
                        'image_caption' => $sectionData['image_caption'] ?? null,
                        'image_decorative' => filter_var($sectionData['image_decorative'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'order' => $index,
                    ]);
                }
            }

            $currentMedia = array_merge([$blog->cover_image, $blog->author_avatar], $blog->sections()->pluck('image_path')->all());
            BlogMedia::removeAfterCommit(array_diff($previousMedia, $currentMedia));

            app(ContentAnalyzer::class)->capture($blog);
            if (!empty($validated['autosave_version'])) {
                \App\Models\BlogAutosave::where('project_id', $blog->project_id)->where('blog_id', $blog->id)->where('user_id', $request->user()->id)->where('version', $validated['autosave_version'])->delete();
            }
            return redirect()->route('admin.project-details', $blog->project_id);
        });
    }

    public function toggleStatus(Blog $blog)
    {
        Gate::authorize('update', $blog->project);

        $blog->status = $blog->status === 'published' ? 'draft' : 'published';
        $blog->published_at = $blog->status === 'published' ? now() : null;
        $blog->save();
        app(ContentAnalyzer::class)->capture($blog);

        return redirect()->back();
    }

    public function destroy(Blog $blog)
    {
        Gate::authorize('update', $blog->project);

        $projectId = $blog->project_id;
        DB::transaction(function () use ($blog) {
            $media = array_merge([$blog->cover_image, $blog->author_avatar], $blog->sections()->pluck('image_path')->all());
            $blog->delete();
            BlogMedia::removeAfterCommit($media);
        });

        return redirect()->route('admin.project-details', $projectId);
    }

    public function move(Request $request, Blog $blog)
    {
        Gate::authorize('update', $blog->project);

        $request->validate([
            'target_project_id' => 'required|exists:projects,id',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $targetProject = Project::findOrFail($request->target_project_id);
        Gate::authorize('update', $targetProject);

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

        app(ContentAnalyzer::class)->capture($blog);
        return redirect()->route('admin.project-details', $sourceProjectId)
            ->with('success', "Blog '{$blog->title}' was moved to {$targetProject->name}.");
    }
}
