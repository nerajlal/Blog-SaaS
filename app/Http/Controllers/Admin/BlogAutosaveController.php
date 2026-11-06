<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\BlogAutosave;
use App\Services\Editor\BlogRevision;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BlogAutosaveController extends Controller
{
    private const ROOTS = ['title', 'category_id', 'image_credits', 'author_name', 'author_title', 'cover_image_alt', 'cover_image_caption', 'cover_image_decorative', 'is_cornerstone', 'status', 'published_at'];
    private const SEO = ['title', 'description', 'canonical_url', 'focus_keyphrase', 'robots_index', 'robots_follow', 'og_title', 'og_description', 'og_image_url', 'x_title', 'x_description', 'x_image_url', 'schema_type'];

    private function query(Request $request, Blog $blog)
    {
        return BlogAutosave::where('project_id', $blog->project_id)->where('blog_id', $blog->id)->where('user_id', $request->user()->id);
    }

    public function show(Request $request, Blog $blog, BlogRevision $revision)
    {
        Gate::authorize('update', $blog->project);
        $draft = $this->query($request, $blog)->first();
        return response()->json(['draft' => $draft ? ['fields' => $draft->snapshot, 'version' => $draft->version, 'saved_at' => $draft->updated_at->toIso8601String(), 'stale' => !hash_equals($draft->base_hash, $revision->hash($blog))] : null])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Blog $blog, BlogRevision $revision)
    {
        Gate::authorize('update', $blog->project);
        $data = $request->validate(['base_hash' => 'required|string|size:64', 'version' => 'required|integer|min:0|max:2147483646', 'fields' => 'required|array|min:1|max:1000', 'fields.*' => 'nullable|string|max:100000']);
        abort_if(strlen(json_encode($data['fields'])) > 2000000, 413, 'Draft exceeds the 2 MB text limit. Save the post manually.');
        $images = $blog->sections()->pluck('image_path')->filter()->all();
        $fields = [];
        foreach ($data['fields'] as $name => $value) {
            $valid = in_array($name, self::ROOTS, true);
            if (preg_match('/^seo\[([a-z_]+)\]$/', $name, $m)) $valid = in_array($m[1], self::SEO, true);
            if (preg_match('/^sections\[(\d{1,2})\]\[(text|existing_image|image_alt|image_caption|image_decorative)\]$/', $name, $m)) {
                $valid = true;
                if ($m[2] === 'text') $value = RichText::clean($value);
                if ($m[2] === 'existing_image' && $value && !in_array($value, $images, true)) $valid = false;
            }
            if (!$valid) throw ValidationException::withMessages(['fields' => 'The draft contains unsupported fields or an image outside this story.']);
            $fields[$name] = $value ?? '';
        }
        $draft = DB::transaction(function () use ($request, $blog, $revision, $data, $fields) {
            $current = Blog::whereKey($blog->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->project_id === $blog->project_id && hash_equals($revision->hash($current), $data['base_hash']), 409, 'The saved post changed. Keep this tab open and review the latest post before saving.');
            $draft = $this->query($request, $current)->lockForUpdate()->first();
            abort_unless(($draft?->version ?? 0) === (int) $data['version'], 409, 'Another tab changed your recovery draft. Keep this tab open and review it before continuing.');
            $draft ??= new BlogAutosave(['project_id' => $current->project_id, 'blog_id' => $current->id, 'user_id' => $request->user()->id]);
            $draft->fill(['base_hash' => $data['base_hash'], 'version' => $data['version'] + 1, 'snapshot' => $fields])->save();
            return $draft;
        });
        return response()->json(['version' => $draft->version, 'saved_at' => $draft->updated_at->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, Blog $blog)
    {
        Gate::authorize('update', $blog->project);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        // A tombstone version prevents stale tabs from recreating a discarded draft.
        DB::transaction(function () use ($request, $blog, $data) {
            $current = Blog::whereKey($blog->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->project_id === $blog->project_id, 409);
            $draft = $this->query($request, $current)->lockForUpdate()->firstOrFail();
            abort_unless($draft->version === (int) $data['version'], 409, 'Another tab changed this recovery draft.');
            $draft->update(['snapshot' => [], 'version' => $draft->version + 1]);
        });
        return response()->json(['version' => $data['version'] + 1])->header('Cache-Control', 'private, no-store');
    }
}
