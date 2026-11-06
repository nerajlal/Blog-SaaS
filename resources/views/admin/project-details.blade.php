@extends('layouts.app')

@section('title', $project->name.' — BlogSaaS')

@section('header_left')
    <a href="{{ route('admin.projects') }}" class="mr-4 text-slate-400 hover:text-brand-600 transition-colors">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
    </a>
    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight uppercase">{{ $project->name }}</h1>
@endsection

@section('content')
<div class="max-w-5xl mx-auto space-y-8">
    <div class="project-hero"><div><p class="product-eyebrow"><span class="product-status-dot"></span> Your publication</p><h2>{{ $project->name }}</h2><p>{{ $project->description ?: 'A thoughtful home for your stories, from the first spark to the moment you hit publish.' }}</p></div><a href="#api-docs" class="product-button product-button-light">Connect your website <span aria-hidden="true">↗</span></a></div>
    <!-- Stories first; setup stays available below. -->
    <section class="stories-section">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
            <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight uppercase">Your stories</h2>
            @can('update', $project)
            <a href="{{ route('admin.create-blog', $project->id) }}" class="inline-flex justify-center px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-sm font-bold uppercase tracking-wider transition-colors shadow-lg shadow-brand-500/20 items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                New story
            </a>
            @endcan
        </div>

        <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm ring-1 ring-slate-200/50">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[600px]" aria-label="Project stories">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold uppercase tracking-widest text-slate-500">
                            <th class="p-5 w-2/5">Title</th>
                            <th class="p-5">Category</th>
                            <th class="p-5">Status</th>
                            <th class="p-5">Date</th>
                            <th class="p-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($blogs as $blog)
                        <tr class="hover:bg-slate-50 transition-colors group">
                            <td class="p-5">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 rounded-xl bg-slate-100 overflow-hidden mr-4 flex-shrink-0 border border-slate-200">
                                        @if($blog->cover_image)
                                        <img src="{{ $blog->cover_image }}" alt="Cover" class="w-full h-full object-cover">
                                        @else
                                        <div class="w-full h-full bg-slate-100 flex items-center justify-center text-slate-400 text-xs font-medium uppercase">No IMG</div>
                                        @endif
                                    </div>
                                    <div>
                                        <h3 class="text-slate-900 font-bold group-hover:text-brand-600 transition-colors">{{ $blog->title }}</h3>
                                        <div class="flex items-center mt-2">
                                            @if($blog->author_avatar)
                                            <img src="{{ $blog->author_avatar }}" alt="Author" class="w-5 h-5 rounded-full mr-2 object-cover">
                                            @else
                                            <div class="w-5 h-5 rounded-full bg-slate-200 mr-2 flex items-center justify-center text-[10px] font-bold text-slate-500 uppercase">
                                                {{ substr($blog->author_name ?: $blog->user->name, 0, 1) }}
                                            </div>
                                            @endif
                                            <p class="text-slate-500 text-xs font-medium">By {{ $blog->author_name ?: $blog->user->name }}</p>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-5">
                                <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                    {{ $blog->category ? $blog->category->name : 'Uncategorized' }}
                                </span>
                            </td>
                            <td class="p-5">
                                @if($blog->status === 'published' && $blog->published_at && $blog->published_at > now())
                                    <div class="flex flex-col">
                                        <span class="text-xs font-bold text-blue-600 uppercase tracking-widest">Scheduled</span>
                                        <span class="text-[10px] text-slate-500 font-medium mt-1">{{ $blog->published_at->format('M d, Y h:i A') }}</span>
                                    </div>
                                @else
                                    @can('update', $project)
                                    <form action="{{ route('admin.blogs.toggle-status', $blog->id) }}" method="POST">
                                        @csrf
                                        @method('PATCH')
                                        <label for="toggle-{{ $blog->id }}" class="flex items-center cursor-pointer select-none">
                                            <div class="relative">
                                                <input type="checkbox" id="toggle-{{ $blog->id }}" class="sr-only" onchange="this.form.submit()" {{ $blog->status === 'published' ? 'checked' : '' }}>
                                                <div class="block w-10 h-6 rounded-full {{ $blog->status === 'published' ? 'bg-brand-500' : 'bg-slate-300' }} transition-colors"></div>
                                                <div class="absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform {{ $blog->status === 'published' ? 'transform translate-x-4' : '' }}"></div>
                                            </div>
                                            <div class="ml-3 text-xs font-bold uppercase tracking-widest {{ $blog->status === 'published' ? 'text-brand-600' : 'text-slate-500' }}">
                                                {{ $blog->status === 'published' ? 'Published' : 'Draft' }}
                                            </div>
                                        </label>
                                    </form>
                                    @else<span class="text-xs text-slate-500">{{ ucfirst($blog->status) }}</span>@endcan
                                @endif
                            </td>
                            <td class="p-5 text-sm font-medium text-slate-600">{{ $blog->created_at->format('M d, Y') }}</td>
                            <td class="p-5 text-right flex justify-end space-x-2">
                                @can('update', $project)
                                <a href="{{ route('admin.blogs.edit', $blog->id) }}" class="p-2 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-xl transition-colors inline-block" title="Edit Blog">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                </a>
                                <button type="button"
                                    onclick="openMoveModal({{ $blog->id }}, {{ json_encode($blog->title) }}, {{ json_encode($blog->category ? $blog->category->name : '') }})"
                                    class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-colors inline-block"
                                    title="Move to another project">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                                </button>
                                <form action="{{ route('admin.blogs.destroy', $blog->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this blog?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-xl transition-colors" title="Delete Blog">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </form>
                                @else<span class="text-xs text-slate-400">Read only</span>@endcan
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="p-8 text-center text-slate-500 font-medium">No blogs created yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-6">{{ $blogs->links() }}</div>
    </section>
    
    <section class="project-tools bg-white border border-slate-200 rounded-2xl p-6">
        <div><h2 class="font-bold text-lg">Search appearance</h2><p class="text-sm text-slate-500 mt-1">{{ $project->seo_verified_at ? 'Public domain verified. Set up your frontend to render SEO metadata and proxy the sitemap.' : 'Configure publication defaults and verify your public domain to enable canonical URLs and sitemaps.' }}</p></div>
        <div class="flex flex-wrap gap-2 shrink-0"><a href="{{ route('admin.links.index', $project) }}" class="border border-slate-300 bg-white font-semibold text-sm px-5 py-3 rounded-full">Links & URLs ↗</a><a href="{{ route('admin.seo.health', $project) }}" class="border border-slate-300 bg-white font-semibold text-sm px-5 py-3 rounded-full">Content health ↗</a>
        @can('manageSeo', $project)<a href="{{ route('admin.search.index',$project) }}" class="border border-slate-300 font-semibold text-sm px-5 py-3 rounded-full">Search performance ↗</a><a href="{{ route('admin.projects.seo', $project) }}" class="shrink-0 bg-blue-600 text-white font-semibold text-sm px-5 py-3 rounded-full">SEO settings ↗</a>@endcan</div>
    </section>
    <!-- API Key Section -->
    <section id="api-docs" class="bg-white border border-slate-200 shadow-sm rounded-3xl p-8 relative overflow-hidden">
        <div class="absolute right-0 top-0 w-64 h-64 bg-brand-50 rounded-full blur-3xl -mr-20 -mt-20"></div>
        <div class="relative z-10">
            <div class="flex items-center mb-6">
                <div class="w-12 h-12 rounded-xl bg-brand-50 border border-brand-100 flex items-center justify-center text-brand-600 mr-4 shadow-sm">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                </div>
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900 tracking-tight uppercase">Project API Key</h2>
                    <p class="text-sm text-slate-500 font-medium">Use this key to fetch your blogs on external websites.</p>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row items-center bg-slate-50 border border-slate-200 rounded-xl p-2 mt-4 gap-2">
                <input id="project-api-key" aria-label="Project read-only API key" type="text" readonly value="{{ $project->api_key }}" class="bg-transparent border-none w-full text-slate-900 font-mono text-sm px-4 py-2 focus:outline-none focus:ring-0">
                <button type="button" onclick="copyProjectKey(this)" class="w-full sm:w-auto px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-sm font-bold uppercase tracking-wider transition-colors shadow-sm flex items-center justify-center whitespace-nowrap">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    Copy
                </button>
            </div>
            <div class="mt-6 pt-6 border-t border-slate-100">
                <p class="text-xs text-slate-500 mb-3">Published content only. Use ?page=2&amp;per_page=20 to paginate (maximum 100). Check meta.last_page for more results.</p>
                <p class="text-sm font-medium text-slate-500">API Endpoint: <code class="text-slate-800 bg-slate-100 px-2 py-1 rounded-md font-mono text-xs border border-slate-200">{{ url('/api/v1/projects/blogs') }}</code></p>
            </div>
        </div>
    </section>

    <!-- Integration Guide Section -->
    <section class="bg-white border border-slate-200 shadow-sm rounded-3xl overflow-hidden">
        <details class="group">
            <summary class="flex items-center justify-between p-6 cursor-pointer list-none hover:bg-slate-50 transition-colors">
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600 mr-4 shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-extrabold text-slate-900 tracking-tight uppercase">Integration Guide</h2>
                        <p class="text-sm text-slate-500 font-medium">Click to see how to fetch blogs on other websites.</p>
                    </div>
                </div>
                <div class="text-slate-400 group-open:rotate-180 transition-transform duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
            </summary>
            <div class="p-6 border-t border-slate-100 bg-slate-50 space-y-6">
                
                <!-- JS Fetch -->
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 uppercase tracking-widest mb-2">1. Vanilla JavaScript (Fetch API)</h3>
                    <div class="bg-slate-900 rounded-xl p-4 overflow-x-auto relative group">
                        <pre class="text-xs text-slate-300 font-mono"><code>const API_KEY = '{{ $project->api_key }}';

fetch('{{ url('/api/v1/projects/blogs') }}', {
    method: 'GET',
    headers: {
        'Accept': 'application/json',
        'X-API-KEY': API_KEY
    }
})
.then(response =&gt; response.json())
.then(data =&gt; console.log(data))
.catch(error =&gt; console.error(error));</code></pre>
                    </div>
                </div>

                <!-- React Axios -->
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 uppercase tracking-widest mb-2">2. React / Vue (Axios)</h3>
                    <div class="bg-slate-900 rounded-xl p-4 overflow-x-auto">
                        <pre class="text-xs text-slate-300 font-mono"><code>import axios from 'axios';

axios.get('{{ url('/api/v1/projects/blogs') }}', {
    headers: { 'X-API-KEY': '{{ $project->api_key }}' }
})
.then(response =&gt; {
    setBlogs(response.data.blogs);
})
.catch(error =&gt; console.error(error));

/* Example Rendering:
{blogs.map(blog =&gt; (
    &lt;article key={blog.id}&gt;
        &lt;h1&gt;{blog.title}&lt;/h1&gt;
        {blog.sections.map(section =&gt; (
            &lt;div key={section.id}&gt;
                {section.image_path &amp;&amp; &lt;img src={`{{ url('') }}${section.image_path}`} /&gt;}
                &lt;p&gt;{section.text_content}&lt;/p&gt;
            &lt;/div&gt;
        ))}
    &lt;/article&gt;
))}
*/</code></pre>
                    </div>
                </div>

                <!-- PHP cURL -->
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 uppercase tracking-widest mb-2">3. PHP (cURL)</h3>
                    <div class="bg-slate-900 rounded-xl p-4 overflow-x-auto">
                        <pre class="text-xs text-slate-300 font-mono"><code>$apiKey = '{{ $project->api_key }}';
$url = '{{ url('/api/v1/projects/blogs') }}';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'X-API-KEY: ' . $apiKey
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

// Example Rendering:
if (isset($data['blogs'])) {
    foreach($data['blogs'] as $blog) {
        echo "&lt;h1&gt;" . htmlspecialchars($blog['title']) . "&lt;/h1&gt;";
        
        // Loop through the dynamic sections
        foreach($blog['sections'] as $section) {
            if ($section['image_path']) {
                echo "&lt;img src='{{ url('') }}" . htmlspecialchars($section['image_path']) . "' /&gt;";
            }
            if ($section['text_content']) {
                echo "&lt;p&gt;" . nl2br(htmlspecialchars($section['text_content'])) . "&lt;/p&gt;";
            }
        }
    }
}</code></pre>
                    </div>
                </div>
                
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl mt-4">
                    <p class="text-xs text-blue-700 font-medium"><strong class="font-extrabold uppercase">Note:</strong> The API returns relative image paths (e.g. <code>/storage/...</code>). Be sure to prefix them with <code>{{ url('') }}</code> on your frontend so images load correctly.</p>
                </div>
            </div>
        </details>
    </section>

    <!-- Categories Section -->
    <section>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
            <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight uppercase">Categories</h2>
        </div>
        
        <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm ring-1 ring-slate-200/50">
            @can('update', $project)
            <form action="{{ route('admin.categories.store', $project->id) }}" method="POST" class="flex gap-2 mb-6">
                @csrf
                <input type="text" name="name" placeholder="New Category Name" required class="flex-1 rounded-xl border-slate-200 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-sm font-bold uppercase tracking-wider transition-colors shadow-lg shadow-brand-500/20">Add</button>
            </form>
            @endcan

            <div class="flex flex-wrap gap-3">
                @forelse($project->categories as $category)
                <div class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium bg-slate-50 border border-slate-200 text-slate-700 group">
                    <span>{{ $category->name }}</span>
                    @can('update', $project)
                    <form action="{{ route('admin.categories.destroy', [$project->id, $category->id]) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this category?');" class="ml-2 inline-flex">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-slate-400 hover:text-red-500 focus:outline-none transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </form>
                    @endcan
                </div>
                @empty
                <p class="text-slate-500 text-sm">No categories yet.</p>
                @endforelse
            </div>
        </div>
    </section>


</div>

<!-- Move Blog Modal -->
<div id="moveBlogModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm opacity-0 pointer-events-none transition-all duration-200">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-lg w-full overflow-hidden transform scale-95 transition-all duration-200" id="moveModalContent">
        <!-- Header -->
        <div class="p-6 border-b border-slate-100 flex items-start justify-between bg-slate-50/50">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 shadow-sm flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900 tracking-tight">Move Blog</h3>
                    <p class="text-xs text-slate-500 font-medium">Transfer this blog to another project</p>
                </div>
            </div>
            <button type="button" onclick="closeMoveModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        @if($otherProjects->isEmpty())
        <!-- No Other Projects State -->
        <div class="p-6 text-center">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-500 border border-amber-200 flex items-center justify-center mx-auto mb-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
            <h4 class="text-base font-bold text-slate-900">No other projects found</h4>
            <p class="text-xs text-slate-500 mt-1 mb-6">You need to have at least one other project created before moving blogs.</p>
            <div class="flex justify-end">
                <button type="button" onclick="closeMoveModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold uppercase tracking-wider rounded-xl transition-colors">
                    Close
                </button>
            </div>
        </div>
        @else
        <!-- Move Form -->
        <form id="moveBlogForm" method="POST" action="" class="p-6 space-y-5">
            @csrf
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Blog to move</span>
                <p id="moveBlogTitle" class="text-sm font-bold text-slate-900 bg-slate-50 border border-slate-200 rounded-xl p-3 line-clamp-2">...</p>
            </div>

            <div>
                <label for="target_project_id" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                    Select Destination Project <span class="text-red-500">*</span>
                </label>
                <select id="target_project_id" name="target_project_id" required onchange="handleTargetProjectChange()"
                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-900 focus:border-brand-500 focus:ring-brand-500 shadow-sm transition-colors">
                    <option value="" disabled selected>-- Choose Destination Project --</option>
                    @foreach($otherProjects as $otherProj)
                        <option value="{{ $otherProj->id }}">{{ $otherProj->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="target_category_id" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                    Category in Destination Project
                </label>
                <select id="target_category_id" name="category_id"
                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-900 focus:border-brand-500 focus:ring-brand-500 shadow-sm transition-colors">
                    <option value="">-- Select a project first --</option>
                </select>
                <p class="text-[11px] text-slate-500 mt-1.5 font-medium">Optional. If matched by category name, it is automatically selected.</p>
            </div>

            <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl p-3.5 flex items-start space-x-3">
                <svg class="w-5 h-5 text-indigo-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <p class="text-xs text-indigo-800 leading-relaxed font-medium">
                    This blog and its sections will be moved to the selected project. API consumers will retrieve it using the destination project's API key.
                </p>
            </div>

            <div class="pt-2 flex items-center justify-end space-x-3 border-t border-slate-100">
                <button type="button" onclick="closeMoveModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold uppercase tracking-wider rounded-xl transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold uppercase tracking-wider rounded-xl shadow-lg shadow-indigo-600/20 transition-all flex items-center">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                    Confirm & Move
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    async function copyProjectKey(button) {
        try {
            await navigator.clipboard.writeText(document.getElementById('project-api-key').value);
            button.textContent = 'Copied';
        } catch {
            document.getElementById('project-api-key').select();
            button.textContent = 'Select and copy';
        }
    }
    const otherProjectsData = @json($otherProjects);
    let currentBlogCategory = '';

    function openMoveModal(blogId, blogTitle, blogCategoryName) {
        currentBlogCategory = blogCategoryName || '';
        const modal = document.getElementById('moveBlogModal');
        const modalContent = document.getElementById('moveModalContent');
        const blogTitleElem = document.getElementById('moveBlogTitle');
        const formElem = document.getElementById('moveBlogForm');
        const projectSelect = document.getElementById('target_project_id');
        const categorySelect = document.getElementById('target_category_id');

        if (blogTitleElem) {
            blogTitleElem.textContent = blogTitle;
        }

        if (formElem) {
            formElem.action = "{{ url('/admin/blogs') }}/" + blogId + "/move";
        }

        if (projectSelect) {
            if (otherProjectsData.length === 1) {
                projectSelect.value = otherProjectsData[0].id;
                handleTargetProjectChange();
            } else {
                projectSelect.value = "";
                if (categorySelect) {
                    categorySelect.innerHTML = '<option value="">-- Select a destination project first --</option>';
                }
            }
        }

        modal.classList.remove('opacity-0', 'pointer-events-none');
        modalContent.classList.remove('scale-95');
        modalContent.classList.add('scale-100');
        document.body.style.overflow = 'hidden';
    }

    function closeMoveModal() {
        const modal = document.getElementById('moveBlogModal');
        const modalContent = document.getElementById('moveModalContent');
        if (!modal) return;

        modal.classList.add('opacity-0', 'pointer-events-none');
        modalContent.classList.remove('scale-100');
        modalContent.classList.add('scale-95');
        document.body.style.overflow = '';
    }

    function handleTargetProjectChange() {
        const projectSelect = document.getElementById('target_project_id');
        const categorySelect = document.getElementById('target_category_id');
        if (!projectSelect || !categorySelect) return;

        const selectedProjectId = parseInt(projectSelect.value);
        const selectedProj = otherProjectsData.find(p => p.id === selectedProjectId);

        categorySelect.innerHTML = '<option value="">-- No Category / Uncategorized --</option>';

        if (selectedProj && selectedProj.categories && selectedProj.categories.length > 0) {
            let matchedId = null;
            selectedProj.categories.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat.id;
                opt.textContent = cat.name;
                if (currentBlogCategory && cat.name.trim().toLowerCase() === currentBlogCategory.trim().toLowerCase()) {
                    matchedId = cat.id;
                }
                categorySelect.appendChild(opt);
            });

            if (matchedId) {
                categorySelect.value = matchedId;
            }
        }
    }

    // Close on Escape or click outside
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMoveModal();
    });

    document.getElementById('moveBlogModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeMoveModal();
    });
</script>
@endpush
