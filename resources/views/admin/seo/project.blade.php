@extends('layouts.app')
@section('title', 'Project SEO — '.$project->name)
@section('header_left')
<a href="{{ route('admin.project-details', $project) }}" class="mr-4 text-slate-500" aria-label="Back to project">←</a>
<h1 class="text-lg font-bold truncate">{{ $project->name }} / SEO settings</h1>
@endsection
@section('content')
@php($settings = $project->seo_settings ?? [])
<div class="max-w-4xl mx-auto space-y-6">
    <div><p class="text-xs font-semibold text-blue-600 uppercase tracking-wider">Search appearance</p><h2 class="text-3xl font-bold mt-2">A clear identity for your publication.</h2><p class="text-sm text-slate-500 mt-3">Set shared defaults here. Authors can customize individual stories in the editor. Your website must render the SEO data returned by the API.</p></div>
    <form method="POST" action="{{ route('admin.projects.seo.update', $project) }}" class="bg-white border border-slate-200 rounded-2xl p-5 sm:p-8 space-y-7">
        @csrf @method('PUT')
        <section class="space-y-4"><h3 class="font-bold text-lg">Publication and URLs</h3><div class="grid sm:grid-cols-2 gap-5">
            <div><label for="site_name" class="block text-sm font-medium mb-2">Publication name</label><input id="site_name" name="site_name" required maxlength="120" value="{{ old('site_name', $settings['site_name'] ?? $project->name) }}" class="w-full border border-slate-300 rounded-lg p-3"></div>
            <div><label for="locale" class="block text-sm font-medium mb-2">Language tag</label><input id="locale" name="locale" required maxlength="35" value="{{ old('locale', $settings['locale'] ?? 'en') }}" placeholder="en or en-IN" class="w-full border border-slate-300 rounded-lg p-3"></div>
            <div><label for="public_url" class="block text-sm font-medium mb-2">Public website origin</label><input id="public_url" name="public_url" type="url" maxlength="255" value="{{ old('public_url', $settings['public_url'] ?? '') }}" placeholder="https://publication.example.com" class="w-full border border-slate-300 rounded-lg p-3"><p class="text-xs text-slate-500 mt-2">The actual website your readers visit. Use HTTPS without a path. Save, then verify below.</p></div>
            <div><label for="article_path" class="block text-sm font-medium mb-2">Article URL pattern</label><input id="article_path" name="article_path" required maxlength="255" value="{{ old('article_path', $settings['article_path'] ?? '/blog/{slug}') }}" class="w-full border border-slate-300 rounded-lg p-3 font-mono text-sm"><p class="text-xs text-slate-500 mt-2">One {slug}, at the end. Examples: /blog/{slug} or /journal/{slug}/.</p></div>
        </div></section>
        <section class="space-y-4 border-t border-slate-100 pt-6"><h3 class="font-bold text-lg">Search defaults</h3><div><label for="title_template" class="block text-sm font-medium mb-2">Title template</label><input id="title_template" name="title_template" required maxlength="255" value="{{ old('title_template', $settings['title_template'] ?? '{title} | {site_name}') }}" class="w-full border border-slate-300 rounded-lg p-3"><p class="text-xs text-slate-500 mt-2">Supported variables: {title} and {site_name}. No HTML.</p></div><div><label for="description" class="block text-sm font-medium mb-2">Fallback description (optional)</label><textarea id="description" name="description" maxlength="500" rows="3" class="w-full border border-slate-300 rounded-lg p-3">{{ old('description', $settings['description'] ?? '') }}</textarea><p class="text-xs text-slate-500 mt-2">Leave blank to derive each story’s description from its content.</p></div><div class="grid sm:grid-cols-2 gap-5">
            <div><label for="index" class="block text-sm font-medium mb-2">Default indexing</label><select id="index" name="index" class="w-full border border-slate-300 rounded-lg p-3"><option value="1" @selected(old('index', $settings['index'] ?? true))>Allow indexing</option><option value="0" @selected(!old('index', $settings['index'] ?? true))>Request noindex</option></select></div>
            <div><label for="follow" class="block text-sm font-medium mb-2">Default link following</label><select id="follow" name="follow" class="w-full border border-slate-300 rounded-lg p-3"><option value="1" @selected(old('follow', $settings['follow'] ?? true))>Allow following</option><option value="0" @selected(!old('follow', $settings['follow'] ?? true))>Request nofollow</option></select></div>
        </div><p class="text-xs text-slate-500">Crawler preferences do not make content private. Use drafts for unpublished content.</p></section>
        <section class="space-y-4 border-t border-slate-100 pt-6"><h3 class="font-bold text-lg">Publisher and sharing</h3><div class="grid sm:grid-cols-2 gap-5">
            <div><label for="publisher_type" class="block text-sm font-medium mb-2">Publisher type</label><select id="publisher_type" name="publisher_type" class="w-full border border-slate-300 rounded-lg p-3">@foreach(['Organization', 'Person'] as $type)<option @selected(old('publisher_type', $settings['publisher_type'] ?? 'Organization') === $type)>{{ $type }}</option>@endforeach</select></div>
            <div><label for="publisher_name" class="block text-sm font-medium mb-2">Publisher name (optional)</label><input id="publisher_name" name="publisher_name" maxlength="120" value="{{ old('publisher_name', $settings['publisher_name'] ?? '') }}" placeholder="Defaults to publication name" class="w-full border border-slate-300 rounded-lg p-3"></div>
            @foreach(['publisher_logo_url' => 'Publisher logo URL', 'social_image_url' => 'Fallback social image URL', 'media_url' => 'Media origin (optional)'] as $field => $label)
            <div class="{{ $field === 'media_url' ? 'sm:col-span-2' : '' }}"><label for="{{ $field }}" class="block text-sm font-medium mb-2">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="url" value="{{ old($field, $settings[$field] ?? '') }}" placeholder="https://…" class="w-full border border-slate-300 rounded-lg p-3"></div>
            @endforeach
        </div><p class="text-xs text-slate-500">Media origin defaults to the BlogSaaS application URL. Use your CDN origin if /storage/ images are served there.</p></section>
        <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-full">Save SEO defaults</button>
    </form>
    @if(!empty($settings['public_url']))
    <section class="bg-white border border-slate-200 rounded-2xl p-5 sm:p-8 space-y-4">
        <div class="flex items-center gap-3"><h3 class="font-bold text-lg">Verify the public domain</h3><span class="text-xs rounded-full px-3 py-1 {{ $project->seo_verified_at ? 'text-emerald-700 bg-emerald-50' : 'text-amber-700 bg-amber-50' }}">{{ $project->seo_verified_at ? 'Verified' : 'Not verified' }}</span></div>
        <p class="text-sm text-slate-500">Add this TXT record to your domain’s DNS settings, then check verification. DNS changes can take time to propagate. Changing the public origin resets verification.</p>
        <dl class="text-sm space-y-3 bg-slate-50 border border-slate-200 rounded-xl p-4 break-all"><dt class="font-semibold">TXT record name</dt><dd class="font-mono">_blogsaas-verification.{{ parse_url($settings['public_url'], PHP_URL_HOST) }}</dd><dt class="font-semibold">TXT record value</dt><dd class="font-mono">blogsaas={{ $project->seo_domain_token }}</dd></dl>
        <form method="POST" action="{{ route('admin.projects.seo.verify', $project) }}">@csrf<button class="px-5 py-2 rounded-full bg-slate-900 text-white text-sm font-semibold">Check DNS verification</button></form>
        @if($project->seo_verified_at)<p class="text-xs text-slate-500">Verified {{ $project->seo_verified_at->toDayDateTimeString() }}. Verification proves domain control; it does not configure your frontend or guarantee indexing.</p>@endif
    </section>
    @endif
    <section class="bg-blue-50 text-blue-900 border border-blue-100 p-5 rounded-2xl space-y-2"><h3 class="font-bold">Connect the public website</h3><p class="text-sm">Read each post’s <code>seo</code> object and render it server-side. Proxy <code class="break-all">{{ url('/api/v1/projects/sitemap.xml') }}</code> with your project’s X-API-KEY header. The sitemap response exposes the page count in X-Sitemap-Pages; serve a public-origin sitemap index for multiple pages.</p><p class="text-sm">The repository includes a runnable PHP reference frontend in <code>examples/headless-php</code>. BlogSaaS does not host your article pages automatically.</p></section>
</div>
@endsection
