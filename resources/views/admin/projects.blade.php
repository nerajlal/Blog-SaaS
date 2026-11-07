@extends('layouts.app')
@section('title', 'Your projects — BlogSaaS')
@section('header_left')<h1>Your workspace <span class="text-slate-300 mx-2" aria-hidden="true">/</span> Projects</h1>@endsection
@section('content')
<div class="max-w-6xl mx-auto">
    <div class="dashboard-intro"><div><p class="product-eyebrow"><span class="product-status-dot"></span> {{ $workspace->name }}</p><h2>Your ideas, in motion.</h2><p class="dashboard-subtitle">A little inspiration. A lot of possibility. Let’s make something worth reading.</p></div>@can('createInWorkspace',[\App\Models\Project::class,$workspace])<a href="#new-project" class="product-button product-button-dark">New project <span aria-hidden="true">＋</span></a>@endcan</div>
    <div class="dashboard-overview" aria-label="Workspace overview">@foreach(['projects'=>['Projects','A space for every idea',''], 'stories'=>['Total stories','Includes scheduled stories','lilac'], 'published'=>['Published','Out there in the world','mint'], 'drafts'=>['Drafts','Good things in progress','sand']] as $key=>$metric)<div class="dashboard-stat"><span class="dashboard-stat-label"><span class="stat-dot {{ $metric[2] }}" aria-hidden="true"></span>{{ $metric[0] }}</span><strong>{{ number_format($stats[$key]) }}</strong><small>{{ $metric[1] }}</small></div>@endforeach</div>
    <div class="dashboard-section-heading"><h3>A place for every project.</h3><span>{{ $projects->total() }} {{ \Illuminate\Support\Str::plural('project',$projects->total()) }} in this workspace</span></div>
    @if(!$projects->total())<p class="dashboard-empty">Your next chapter starts here. Create a project for your publication, product, or next big idea.</p>@endif
    <div class="project-grid">
        @foreach($projects as $project)
        <article class="project-card">
            <div class="project-card-link">
                <div class="project-card-top"><span class="project-card-icon palette-{{ $loop->index % 3 }}">{{ mb_substr($project->name,0,1) }}</span><span><i class="stat-dot" aria-hidden="true"></i> Your publication</span></div>
                <div class="project-title-row">
                    <h4><a href="{{ route('admin.project-details', $project) }}">{{ $project->name }}</a></h4>
                    @can('delete', $project)
                    <button type="button" class="project-trash-button" data-open-dialog="delete-dialog-{{ $project->id }}" aria-label="Delete project: {{ $project->name }}" title="Delete project">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/></svg>
                    </button>
                    @endcan
                </div>
                <p class="project-card-description">{{ $project->description ?: 'A little space for stories, fresh perspectives, and what comes next.' }}</p>
                <div class="project-card-bottom"><span><strong>{{ $project->blogs_count }}</strong> {{ \Illuminate\Support\Str::plural('story',$project->blogs_count) }} <span aria-hidden="true">·</span> Updated {{ $project->updated_at->diffForHumans() }}</span></div>
                <div class="project-card-actions">
                    <a href="{{ route('admin.project-details', $project) }}" class="project-action project-action-primary"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>Blogs</a>
                    <a href="{{ route('admin.projects.integration', $project) }}" class="project-action project-action-secondary"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 8-4 4 4 4m8-8 4 4-4 4m-3-11-2 14"/></svg>Integration</a>
                </div>
            </div>
            @can('delete', $project)
            <dialog id="delete-dialog-{{ $project->id }}" class="project-delete-dialog" aria-labelledby="delete-title-{{ $project->id }}">
                <h2 id="delete-title-{{ $project->id }}">Delete project?</h2>
                <form action="{{ route('admin.projects.destroy', $project) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <p>Permanently delete <strong>{{ $project->name }}</strong>, its {{ $project->blogs_count }} stories, drafts, categories, and SEO data. This cannot be undone.</p>
                    <label for="delete-project-{{ $project->id }}">Type {{ $project->name }} to confirm</label>
                    <input id="delete-project-{{ $project->id }}" name="project_name" required autocomplete="off" spellcheck="false">
                    <div class="project-delete-actions"><button type="button" class="product-button" data-close-dialog autofocus>Cancel</button><button type="submit" class="product-button project-delete-button">Delete project</button></div>
                </form>
            </dialog>
            @endcan
        </article>
        @endforeach
        @can('createInWorkspace',[\App\Models\Project::class,$workspace])<form id="new-project" action="{{ route('admin.projects.store') }}" method="POST" class="project-card project-create-card">@csrf<div class="project-create-title"><span aria-hidden="true">＋</span><h4>Make room for something new.</h4></div><label for="project-name">Project name</label><input id="project-name" name="name" required maxlength="255" value="{{ old('name') }}" placeholder="Your next chapter"><label for="project-description">A little about it <span>(optional)</span></label><input id="project-description" name="description" value="{{ old('description') }}" maxlength="10000" placeholder="What’s the story?"><button type="submit" class="product-button product-button-dark">Create Project <span aria-hidden="true">↗</span></button></form>@endcan
    </div>
    <div class="mt-6">{{ $projects->links() }}</div>
    <div class="dashboard-tip"><span class="dashboard-tip-icon" aria-hidden="true">✳</span><div><strong>Your content. Any frontend.</strong><p>Write here, publish wherever you feel at home. Every project comes with its own content API.</p></div><a href="{{ route('docs') }}#developers">A little inspiration <span aria-hidden="true">↗</span></a></div>
</div>
@endsection
