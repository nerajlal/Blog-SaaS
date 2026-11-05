@extends('layouts.app')

@section('title', 'Projects - Blog Admin Panel')

@section('header_left')
    <h1 class="text-xl font-extrabold text-slate-900 uppercase tracking-tight">Projects</h1>
@endsection

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
        <div>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight uppercase">Your Projects</h2>
            <p class="text-slate-500 mt-1 font-medium text-sm sm:text-base">Manage your blog projects and API keys.</p>
        </div>
    </div>

    <!-- Projects Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach ($projects as $project)
        <!-- Project Card -->
        <a href="{{ route('admin.project-details', $project->id) }}" class="group bg-white border border-slate-200 rounded-2xl p-6 hover:shadow-xl hover:shadow-brand-500/5 hover:border-brand-200 transition-all duration-300 relative overflow-hidden flex flex-col min-h-[240px]">
            <div class="absolute top-0 right-0 w-32 h-32 bg-brand-50 rounded-full blur-3xl -mr-16 -mt-16 transition-opacity opacity-0 group-hover:opacity-100"></div>
            <div class="flex items-start justify-between mb-4 relative z-10">
                <div class="w-12 h-12 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:bg-brand-50 group-hover:text-brand-500 group-hover:border-brand-100 transition-colors shadow-sm">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-brand-50 text-brand-600 border border-brand-200 uppercase tracking-widest">Active</span>
            </div>
            <h3 class="text-xl font-extrabold text-slate-900 mb-2 group-hover:text-brand-600 transition-colors relative z-10">{{ $project->name }}</h3>
            <p class="text-slate-500 text-sm mb-6 line-clamp-2 font-medium relative z-10 flex-1">{{ $project->description ?? 'No description.' }}</p>
            <div class="flex items-center justify-between text-sm pt-4 border-t border-slate-100 relative z-10 mt-auto">
                <span class="text-slate-500 font-medium"><span class="text-slate-900 font-extrabold">{{ $project->blogs_count }}</span> Blogs</span>
                <span class="text-slate-400 text-xs font-semibold uppercase tracking-wider">Updated {{ $project->updated_at->diffForHumans() }}</span>
            </div>
        </a>
        @endforeach

        <!-- Empty State / Create New -->
        <form action="{{ route('admin.projects.store') }}" method="POST" class="bg-white border-2 border-dashed border-slate-300 hover:border-brand-300 hover:bg-brand-50/30 rounded-2xl p-6 flex flex-col items-center justify-center transition-all group min-h-[240px]">
            @csrf
            <div class="w-12 h-12 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 mb-4 group-hover:text-brand-500 group-hover:bg-brand-100 transition-colors shadow-sm ring-1 ring-slate-200 group-hover:ring-brand-200">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
            </div>
            <input type="text" name="name" placeholder="New Project Name" required class="w-full mb-3 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-900 font-medium focus:bg-white focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none transition-shadow">
            <input type="text" name="description" placeholder="Description..." class="w-full mb-4 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-900 font-medium focus:bg-white focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none transition-shadow">
            <button type="submit" class="w-full py-3 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider shadow-sm transition-colors">Create Project</button>
        </form>
    </div>
</div>
@endsection
