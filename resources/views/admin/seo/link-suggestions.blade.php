@extends('layouts.app')
@section('title', 'Link suggestions — '.$blog->title)
@section('header_left')
<a href="{{ route('admin.links.index', $project) }}" class="mr-4 text-slate-500">← Links</a><h1 class="font-bold">Internal-link suggestions</h1>
@endsection
@section('content')
<div class="max-w-3xl mx-auto space-y-5"><h2 class="text-2xl font-bold">{{ $blog->title }}</h2><p class="text-sm text-slate-500">{{ $suggestions['note'] }}</p><p class="text-sm">Review these targets. Each approval appends one Related reading section; existing paragraphs stay intact. Suggestions use saved content.</p>
@forelse($suggestions['items'] as $item)<article class="p-5 border rounded-xl bg-white"><h3 class="font-bold">{{ $item['blog']->title }}</h3><p class="text-sm text-slate-500 mt-2">{{ $item['reason'] }}</p><p class="mt-3 text-sm break-all select-all">{{ $item['url'] }}</p>@can('update', $project)<form method="POST" action="{{ route('admin.links.insert', $blog) }}" class="mt-4">@csrf<input type="hidden" name="input_hash" value="{{ $inputHash }}"><input type="hidden" name="target_id" value="{{ $item['blog']->id }}"><button class="border rounded-lg px-4 py-2 text-sm">Approve and append this link</button></form>@endcan</article>@empty<div class="p-6 border rounded-xl bg-white text-sm">No suitable unlinked targets found in the current candidate set.</div>@endforelse
@can('update', $project)@foreach($edits as $edit)<div class="border rounded-xl p-4 text-sm break-all"><p>{{ $edit->target_url }} · {{ $edit->reverted_at ? 'Reverted' : 'Inserted' }}</p>@if(!$edit->reverted_at)<form method="POST" action="{{ route('admin.links.undo', [$blog,$edit]) }}" class="mt-3">@csrf<button class="text-blue-700">Undo unchanged inserted section</button></form>@endif</div>@endforeach<a href="{{ route('admin.blogs.edit', $blog) }}" class="inline-block px-5 py-3 rounded-lg bg-blue-600 text-white text-sm">Edit story</a>@endcan</div>
@endsection
