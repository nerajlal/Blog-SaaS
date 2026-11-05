<aside id="workspace-sidebar" class="fixed top-0 left-0 bottom-0 w-64 bg-slate-900 border-r border-slate-800 hidden md:flex flex-col shadow-xl z-40 text-slate-300">
    <!-- Header / Brand -->
    <div class="h-16 flex items-center px-6 border-b border-slate-800 flex-shrink-0 justify-between">
        <div class="flex items-center">
            <div class="w-8 h-8 bg-indigo-600 rounded-xl mr-3 flex items-center justify-center shadow-lg shadow-indigo-500/20 text-white">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path></svg>
            </div>
            <span class="font-bold text-base tracking-wide text-white">Blog SaaS</span>
        </div>
        <button id="sidebar-close" type="button" aria-label="Close workspace navigation" class="md:hidden p-2 text-slate-300">×</button>
    </div>

    <!-- Workspace Selector -->
    <div class="px-4 py-4 border-b border-slate-800">
        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Active Workspace</label>
        <form action="{{ route('workspaces.switch', ['workspace' => ':id']) }}" method="POST" id="workspace-switch-form" class="relative">
            @csrf
            <select onchange="this.form.action = this.selectedOptions[0].dataset.switchUrl; this.form.submit();"
                class="w-full bg-slate-800 border border-slate-700 text-white text-xs rounded-xl px-3 py-2.5 outline-none focus:border-indigo-500 transition cursor-pointer">
                @if(isset($workspaces))
                    @foreach($workspaces as $ws)
                        <option data-switch-url="{{ route('workspaces.switch', $ws) }}" value="{{ $ws->id }}" {{ auth()->user()->current_workspace_id === $ws->id ? 'selected' : '' }}>
                            {{ $ws->name }}
                        </option>
                    @endforeach
                @else
                    <option>{{ auth()->user()->name }}'s Team</option>
                @endif
            </select>
        </form>
        <form action="{{ route('workspaces.store') }}" method="POST" class="mt-3 flex gap-1">
            @csrf
            <label for="new-workspace" class="sr-only">New workspace name</label>
            <input id="new-workspace" name="name" required maxlength="255" placeholder="New workspace" class="min-w-0 w-full rounded-lg bg-slate-800 border border-slate-700 px-2 py-2 text-xs text-white">
            <button type="submit" aria-label="Create workspace" class="px-3 bg-indigo-600 rounded-lg text-white">+</button>
        </form>
    </div>

    <!-- Navigation links -->
    <nav class="flex-1 py-6 px-4 space-y-1.5 overflow-y-auto">
        <a href="{{ route('admin.projects') }}" class="flex items-center px-4 py-2.5 bg-indigo-600/10 text-indigo-400 border border-indigo-500/20 rounded-xl transition-colors font-medium text-sm">
            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            <span>Projects</span>
        </a>

        <a href="{{ route('docs') }}#developers" class="flex items-center px-4 py-2.5 text-slate-400 hover:bg-slate-800/60 hover:text-white rounded-xl transition-colors font-medium text-sm">
            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
            <span>API & Docs</span>
        </a>


    </nav>

    <!-- User / Logout Footer -->
    <div class="p-4 border-t border-slate-800 flex-shrink-0">
        <div class="flex items-center justify-between mb-3 px-2">
            <div class="flex items-center space-x-2">
                <div class="w-7 h-7 rounded-full bg-indigo-500/20 text-indigo-300 flex items-center justify-center font-bold text-xs uppercase">
                    {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
                </div>
                <div class="truncate max-w-[120px]">
                    <p class="text-xs font-semibold text-white truncate">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-[10px] text-slate-400 truncate">{{ auth()->user()->email ?? '' }}</p>
                </div>
            </div>
        </div>

        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" class="w-full flex items-center px-3 py-2 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-colors text-xs font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                <span>Sign Out</span>
            </button>
        </form>
    </div>
</aside>
