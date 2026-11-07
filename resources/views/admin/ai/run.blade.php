@extends('layouts.app')
@section('title', 'Review AI suggestion — '.$project->name)
@section('header_left')<h1>Review suggestion</h1>@endsection
@section('content')
<div class="ai-page">
    <div class="project-hero"><div><p class="product-eyebrow">{{ $project->name }} · {{ $run->model }}</p><h2>{{ Str::headline($run->action) }}{{ $run->platform ? ' · '.Str::headline($run->platform) : '' }}</h2><p>Review the words. Make them yours. You decide what happens next.</p></div><a href="{{ route('admin.ai.studio', [$project, 'blog' => $run->blog_id]) }}" class="product-button product-button-light">Back to AI studio ↗</a></div>
    @if($stale)<div role="alert" class="ai-notice ai-warning">The source story or approved profile has changed. This suggestion is stale. You can keep or export the text, but generate a current version before marking it reviewed or creating a draft.</div>@endif
    <div class="ai-status-row"><span class="ai-badge">{{ $run->state }}</span><span>Profile {{ $run->profileVersion ? 'v'.$run->profileVersion->revision : 'not yet approved' }} · {{ $run->reviewed_at ? 'Reviewed '.$run->reviewed_at->diffForHumans() : 'Needs review' }}</span></div>
    @if(in_array($run->state,['pending','running']))
        <section class="ai-panel" data-ai-poll="{{ route('admin.ai.status', [$project, $run]) }}" data-ai-state="{{ $run->state }}"><h3 role="status">{{ $run->state === 'pending' ? 'Waiting for the AI worker…' : 'Working on your suggestion…' }}</h3><p class="ai-muted">You can leave this page and return from recent suggestions. Check that the AI queue worker is running if a task stays pending.</p>@can('update', $project)<form action="{{ route('admin.ai.cancel', [$project, $run]) }}" method="POST">@csrf<button class="product-button product-button-light">Cancel task</button></form>@endcan</section>
    @endif
    @if($run->detail)<div class="ai-notice">{{ $run->detail }}</div>@endif
    @if($run->state === 'complete')
    <div class="ai-two-columns">
        <section class="ai-panel"><h3>Your suggestion</h3><p class="ai-muted">Check names, factual claims, source references, and links. Save edits before copying, exporting, or creating a draft.</p>
            @if($run->action === 'social' && ($run->input['public_url'] ?? '[BLOG_URL]') === '[BLOG_URL]')<div class="ai-notice">Link placeholder: this pack needs a confirmed public blog URL before sharing. Instagram carousel output is slide text only.</div>@endif
            @if($run->platform === 'reddit' && empty($run->input['community_rules']))<div class="ai-notice">Community rules were not supplied. Review subreddit rules and disclose your business relationship before sharing.</div>@endif
            <form action="{{ route('admin.ai.result.save', [$project, $run]) }}" method="POST" class="ai-form" data-ai-review>@csrf @method('PUT')<input type="hidden" name="revision" value="{{ $run->revision }}">
                @php($canEdit = auth()->user()->can($run->action === 'analyze' ? 'manageSeo' : 'update', $project))
                @foreach($fields as $field => $rule)
                <label for="result-{{ $field }}">{{ Str::headline($field) }} @if($field === 'body_html')<small>· safe HTML</small>@endif</label>
                <textarea id="result-{{ $field }}" name="result[{{ $field }}]" rows="{{ in_array($field,['body_html','body','outline']) ? 10 : 3 }}" @readonly(!$canEdit)>{{ old('result.'.$field, ($run->edited ?? $run->result)[$field] ?? '') }}</textarea>
                <button type="button" class="ai-copy" data-ai-copy="result-{{ $field }}">Copy {{ Str::headline($field) }}</button>
                @endforeach
                @if($canEdit)<label class="ai-check"><input type="checkbox" name="reviewed" value="1" @checked($run->reviewed_at) @disabled($stale)> I checked this content and its claims, sources, and destinations.</label><button class="product-button product-button-dark">Save edits & review status</button>@endif
            </form>
        </section>
        <aside class="ai-side-stack">
            <section class="ai-panel"><h3>Next step</h3>
                <a href="{{ route('admin.ai.export', [$project, $run]) }}" class="product-button product-button-light" data-ai-needs-clean>Export saved text ↓</a><p class="ai-muted">Exports use saved edits. Reviewed means ready for your review workflow; it does not mean published or posted.</p>
                @can('update', $project)
                    @if($run->action === 'outline')<form action="{{ route('admin.ai.generate', $project) }}" method="POST" class="ai-form" data-ai-form data-ai-needs-clean>@csrf<input type="hidden" name="action" value="draft"><input type="hidden" name="parent_id" value="{{ $run->id }}"><input type="hidden" name="request_key" value="{{ (string) Str::uuid() }}"><fieldset @disabled($stale || !$run->reviewed_at)><label class="ai-check"><input type="checkbox" name="consent" value="1" required> Use this reviewed outline and approved project facts with Claude to generate a draft.</label><button class="product-button product-button-dark">Generate draft ✧</button></fieldset></form><p class="ai-muted">Save and mark the outline reviewed to continue.</p>@endif
                    @if($run->action === 'draft')
                        @if($run->output_blog_id)<a href="{{ route('admin.blogs.edit', $run->output_blog_id) }}" class="product-button product-button-dark">Open created draft ↗</a>
                        @else<form action="{{ route('admin.ai.draft', [$project, $run]) }}" method="POST" class="ai-form" data-ai-needs-clean>@csrf<input type="hidden" name="revision" value="{{ $run->revision }}"><fieldset @disabled($stale || !$run->reviewed_at)><label for="draft-category">Category</label><select id="draft-category" name="category_id" required><option value="">Choose a category</option>@foreach($project->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select><label class="ai-check"><input type="checkbox" name="confirm" value="1" required> Create a private draft from the saved, reviewed suggestion.</label><button class="product-button product-button-dark">Save as a blog draft</button></fieldset></form>@endif
                    @endif
                @endcan
                @if($run->action === 'analyze')@can('manageSeo', $project)<form action="{{ route('admin.ai.adopt', [$project, $run]) }}" method="POST" class="ai-form" data-ai-needs-clean>@csrf<fieldset @disabled(!$run->reviewed_at)><p>Select the profile fields to replace with this saved proposal:</p>@foreach(['summary','products','audience','tone','facts'] as $field)<label class="ai-check"><input type="checkbox" name="fields[]" value="{{ $field }}">{{ Str::headline($field) }}</label>@endforeach<label class="ai-check"><input type="checkbox" name="confirm" value="1" required> Replace selected draft profile fields. I will review and approve the profile separately.</label><button class="product-button product-button-dark">Use selected suggestions</button></fieldset></form>@endcan @endif
                @if(in_array($run->action,['titles','metadata','rewrite']))<p class="ai-muted">Copy your chosen text into the normal editor. Existing writing is never silently replaced.</p>@if($run->blog)<a href="{{ route('admin.blogs.edit', $run->blog) }}" class="product-button product-button-light">Open story editor ↗</a>@endif @endif
            </section>
            <section class="ai-panel"><h3>Context & sources</h3><p class="ai-muted">Source text is not verified evidence. Check original pages and product claims before approving them.</p><details><summary>View generation context</summary><dl class="ai-context">@foreach($run->input as $key => $value)<dt>{{ Str::headline($key) }}</dt><dd><pre>{{ is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value }}</pre></dd>@endforeach</dl></details>
            @if($run->profileVersion)<details><summary>Approved business profile used</summary><dl class="ai-context">@foreach($run->profileVersion->data as $key => $value)<dt>{{ Str::headline($key) }}</dt><dd>{{ $value }}</dd>@endforeach</dl></details>@endif
            <p class="ai-muted">Usage: {{ number_format($run->usage['input_tokens'] ?? 0) }} input / {{ number_format($run->usage['output_tokens'] ?? 0) }} output tokens. Cache tokens, when supplied, are recorded separately.</p></section>
        </aside>
    </div>
    @endif
</div>
@endsection
