@extends('layouts.app')
@section('title', 'Project details — '.$project->name)
@section('header_left')<h1>Project details</h1>@endsection
@section('content')
<div class="ai-page">
    <div class="project-hero"><div><p class="product-eyebrow">A little context goes a long way</p><h2>{{ $project->name }}</h2><p>Teach your writing assistant about your business, your audience, and what makes you different.</p></div><a href="{{ route('admin.ai.studio', $project) }}" class="product-button product-button-dark">Open AI studio ✧</a></div>
    <div class="ai-notice">{{ $approved ? 'Approved profile v'.$approved->revision.' is available for writing.' : 'Not configured: save your details and have an owner or admin approve them.' }} @if($profile && $approved && $profile->revision !== $approved->revision) <strong>Draft changes need review. AI still uses the last approved version.</strong> @endif</div>
    <div class="ai-two-columns">
        <section class="ai-panel"><h3>Your business, in your words.</h3><p class="ai-muted">Add facts you can stand behind. Include sources for product claims and policies. Website analysis is optional.</p>
            <form action="{{ route('admin.ai.profile.save', $project) }}" method="POST" class="ai-form">@csrf @method('PUT')<input type="hidden" name="revision" value="{{ $profile?->revision ?? 0 }}">
                <fieldset @cannot('manageSeo', $project) disabled @endcannot>
                @foreach($fields as $field => $label)
                    @if($field === 'positioning')<details><summary>Brand voice, policies & social preferences</summary><div class="ai-profile-extra">@endif
                    <label for="profile-{{ $field }}">{{ $label }} @if(in_array($field,['name','summary','audience']))<span aria-hidden="true">*</span>@endif</label>
                    @if(in_array($field,['name','website','business_type','language']))<input id="profile-{{ $field }}" name="data[{{ $field }}]" value="{{ old('data.'.$field, $profile?->data[$field] ?? ($field === 'name' ? $project->name : '')) }}" maxlength="{{ $field === 'website' ? 2048 : 4000 }}" @required(in_array($field,['name','summary','audience']))>
                    @else<textarea id="profile-{{ $field }}" name="data[{{ $field }}]" rows="3" maxlength="4000" @required(in_array($field,['name','summary','audience']))>{{ old('data.'.$field, $profile?->data[$field] ?? '') }}</textarea>@endif
                @endforeach
                </div></details>
                @can('manageSeo', $project)<button class="product-button product-button-dark">Save project details</button>@endcan
                </fieldset>
            </form>
        </section>
        <aside class="ai-side-stack">
            <section class="ai-panel"><span class="ai-badge">Review before use</span><h3>Approve this version</h3><p class="ai-muted">Approval creates a fixed snapshot. It does not publish a story or change existing content.</p>
            @can('manageSeo', $project)
                @if($profile)<form action="{{ route('admin.ai.profile.approve', $project) }}" method="POST" class="ai-form">@csrf<input type="hidden" name="revision" value="{{ $profile->revision }}"><label class="ai-check"><input type="checkbox" name="confirm" value="1" required> I reviewed the saved details above and their sources, and approve version {{ $profile->revision }}.</label><button class="product-button product-button-light">Approve saved version</button></form>@else<p>Save your details first.</p>@endif
            @else<p class="ai-muted">An owner or admin can update and approve the profile.</p>@endcan
            @if($approved)<details class="ai-approved"><summary>View approved facts · v{{ $approved->revision }}</summary>@foreach($approved->data as $key => $value)<h4>{{ $fields[$key] ?? $key }}</h4><p>{{ $value ?: 'Not supplied' }}</p>@endforeach</details>@endif
            </section>
            <section class="ai-panel"><h3>Website analysis</h3><p class="ai-muted">Choose up to three public pages, or paste source text in AI studio. Claude proposes details for you to review.</p><a href="{{ route('admin.ai.studio', $project) }}#website-analysis" class="product-button product-button-light">Analyze your website ↗</a></section>
            <section class="ai-panel"><h3>AI usage</h3><p class="ai-muted">{{ $ready ? 'Claude configuration is available.' : 'Claude is disabled or not configured on this server. Manual details still work.' }}</p><p><strong>{{ $requests }}</strong> requests reserved this month.</p><p class="ai-muted">Allowance is shared by every project in this workspace. Failed and cancelled requests count because provider billing may have occurred. This is a request cap, not a dollar spending guarantee.</p>
            @can('manageSeo', $project)<form method="POST" action="{{ route('admin.ai.settings', $project) }}" class="ai-form">@csrf @method('PUT')<label for="ai-enabled">Workspace AI access</label><select id="ai-enabled" name="enabled"><option value="0" @selected(!$settings?->enabled)>Disabled</option><option value="1" @selected($settings?->enabled)>Enabled</option></select><label for="ai-limit">Monthly request allowance</label><input id="ai-limit" type="number" name="monthly_limit" min="1" max="{{ max(1, config('ai.monthly_requests')) }}" value="{{ min($settings?->monthly_limit ?? 20, max(1, config('ai.monthly_requests'))) }}" required><button class="product-button product-button-light">Save workspace allowance</button></form>@endcan
            </section>
        </aside>
    </div>
</div>
@endsection
