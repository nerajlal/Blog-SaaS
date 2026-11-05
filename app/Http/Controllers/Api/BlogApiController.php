<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Project;
use App\Services\Seo\SeoResolver;
use Illuminate\Http\Request;

class BlogApiController extends Controller
{
    private function project(Request $request): Project
    {
        $key = $request->header('X-API-KEY') ?? $request->query('api_key');
        if (!is_string($key) || $key === '') {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'API key is required.'], 401));
        }
        $project = Project::where('api_key', $key)->first();
        if (!$project) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'Invalid API key.'], 401));
        }
        return $project;
    }

    private function payload(Blog $blog, Project $project, SeoResolver $resolver): array
    {
        $blog->setRelation('project', $project);
        // Keep the existing post fields while withholding private SEO inputs and relations.
        $data = $blog->attributesToArray();
        $data['cover_image_url'] = $resolver->imageUrl($blog->cover_image, $project->seo_settings ?? []);
        $data['sections'] = $blog->sections->map(fn ($section) => array_merge($section->toArray(), ['image_url' => $resolver->imageUrl($section->image_path, $project->seo_settings ?? [])]))->all();
        $data['category'] = $blog->category?->toArray();
        $data['seo'] = $resolver->resolve($blog);
        return $data;
    }

    public function index(Request $request, SeoResolver $resolver)
    {
        $project = $this->project($request);
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $blogs = $project->blogs()->live()->with(['sections', 'category', 'user'])->latest()
            ->paginate((int) $request->input('per_page', 20));
        return response()->json([
            'project' => $project->name,
            'blogs' => $blogs->getCollection()->map(fn ($blog) => $this->payload($blog, $project, $resolver))->all(),
            'meta' => ['current_page' => $blogs->currentPage(), 'last_page' => $blogs->lastPage(), 'per_page' => $blogs->perPage(), 'total' => $blogs->total()],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $slug, SeoResolver $resolver)
    {
        $project = $this->project($request);
        $blog = $project->blogs()->live()->where('slug', $slug)->with(['sections', 'category', 'user'])->firstOrFail();
        return response()->json(['project' => $project->name, 'blog' => $this->payload($blog, $project, $resolver)])->header('Cache-Control', 'no-store');
    }

    public function sitemap(Request $request, SeoResolver $resolver)
    {
        $project = $this->project($request);
        abort_unless($project->seo_verified_at && !empty($project->seo_settings['public_url']), 409, 'Configure and verify the public domain first.');
        $request->validate(['page' => 'sometimes|integer|min:1']);
        // A bounded page is also suitable for proxying from a public-origin sitemap index.
        $blogs = $project->blogs()->live()->orderBy('id')->paginate(1000);
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($blogs as $blog) {
            $blog->setRelation('project', $project);
            $url = $resolver->articleUrl($blog);
            $postSeo = $blog->seo_settings ?? [];
            $index = match ($postSeo['robots_index'] ?? 'inherit') { 'index' => true, 'noindex' => false, default => $project->seo_settings['index'] ?? true };
            if (!$index || !$url || (!empty($postSeo['canonical_url']) && $postSeo['canonical_url'] !== $url)) {
                continue;
            }
            $xml->startElement('url');
            $xml->writeElement('loc', $url);
            $lastmod = $blog->published_at && $blog->published_at->gt($blog->updated_at) ? $blog->published_at : $blog->updated_at;
            $xml->writeElement('lastmod', $lastmod->toIso8601String());
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        return response($xml->outputMemory())->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'no-store')->header('X-Sitemap-Pages', (string) $blogs->lastPage());
    }
}
