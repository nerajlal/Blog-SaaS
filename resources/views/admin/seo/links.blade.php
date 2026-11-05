@extends('layouts.app')
@section('title', 'Links — '.$project->name)
@section('header_left')
<a href="{{ route('admin.project-details', $project) }}" class="mr-4 text-slate-500">← Project</a><h1 class="font-bold">Links & URLs</h1>
@endsection
@section('content')
<div class="max-w-6xl mx-auto space-y-6">
    <div><p class="text-sm text-blue-600">{{ $project->name }}</p><h2 class="text-3xl font-bold mt-2">Connect your stories.</h2><p class="text-sm text-slate-500 mt-3">Inspect saved article links and find stories without incoming links from other live articles. Site navigation and external backlinks are outside this report.</p></div>
    @can('manageSeo', $project)<a href="{{ route('admin.redirects.index', $project) }}" class="inline-block text-sm text-blue-700">Manage redirects ↗</a><a href="{{ route('admin.telemetry.index', $project) }}" class="inline-block ml-5 text-sm text-blue-700">404 reports ↗</a><a href="{{ route('admin.metadata.index', $project) }}" class="inline-block ml-5 text-sm text-blue-700">Bulk metadata ↗</a>@endcan
    <div class="rounded-xl border bg-white p-5 space-y-3">
        <p class="text-sm">@if($run)Index: <strong>{{ $run->status }}</strong> · {{ $run->processed }} stories · {{ $run->omitted }} omitted URLs or stories.@else No current index. @if($latest)Latest build: {{ $latest->status }}.@endif @endif</p>
        @if(!$project->seo_verified_at)<p class="text-sm text-amber-700">Configure and verify your public domain in Search appearance before indexing.</p>@else
        @can('update', $project)<form method="POST" action="{{ route('admin.links.rebuild', $project) }}">@csrf<button class="rounded-lg bg-blue-600 text-white px-4 py-2 text-sm">Rebuild link index</button></form>@endcan
        @endif
        <p class="text-xs text-slate-500">Background work uses the SEO queue. Changes to stories or SEO settings invalidate the report. Partial indexes cannot establish that an article has no links.</p>
    </div>
    @if($run)
    <div class="border rounded-xl bg-white overflow-hidden"><h3 class="font-bold p-5">Saved article links</h3>
    <form method="POST" action="{{ route('admin.links.check', $project) }}">@csrf
    <div class="overflow-x-auto"><table class="w-full text-left text-sm min-w-[640px]"><thead class="bg-slate-50"><tr><th class="p-4">Check</th><th class="p-4">Source / URL</th><th class="p-4">Type</th><th class="p-4">Last result</th></tr></thead><tbody>
    @forelse($links as $link) @php($check = $checks->get($link->url_hash))
    <tr class="border-t"><td class="p-4">@can('update', $project)<input type="checkbox" name="link_ids[]" value="{{ $link->id }}" aria-label="Check URL from {{ $link->blog?->title }}">@endcan</td><td class="p-4 max-w-md"><a class="text-blue-700" href="{{ route('admin.links.suggestions', $link->blog_id) }}">{{ $link->blog?->title }}</a><p class="break-all text-xs mt-2">{{ $link->url }}</p><p class="text-xs text-slate-500 mt-1">{{ $link->anchor }} · {{ $link->occurrences }} occurrence(s)</p></td><td class="p-4">{{ $link->kind }}</td><td class="p-4"><strong>{{ $check?->state ?? 'Not checked' }}</strong> {{ $check?->http_status }}<p class="text-xs text-slate-500 mt-1">{{ $check?->detail }}</p><p class="text-xs">{{ $check?->checked_at?->diffForHumans() }}</p></td></tr>
    @empty<tr><td colspan="4" class="p-6 text-slate-500">No supported links found.</td></tr>@endforelse
    </tbody></table></div>
    @can('update', $project)@if($links->count())<div class="p-4 border-t"><button class="rounded-lg border px-4 py-2 text-sm">Check selected URLs (up to 20)</button><p class="text-xs text-slate-500 mt-2">HEAD requests only. Restricted responses are not classified as broken. One-hour cooldown, project budget of 200 distinct URLs per day.</p></div>@endif @endcan
    </form></div>{{ $links->links() }}
    <div class="rounded-xl border bg-white p-5"><h3 class="font-bold">{{ $run->status === 'partial' ? 'Possible orphan stories (partial index)' : 'Orphan stories' }}</h3><p class="text-xs text-slate-500 mt-2">Live stories with no incoming link from another live article in this index. A story may still be reachable from your website’s navigation.</p>
    @forelse($orphans as $orphan)<a href="{{ route('admin.links.suggestions', $orphan) }}" class="block py-3 border-b text-sm text-blue-700">{{ $orphan->title }} ↗</a>@empty<p class="text-sm mt-4">No orphan stories found.</p>@endforelse<div class="mt-4">{{ $orphans->links() }}</div></div>
    @endif
</div>
@endsection
