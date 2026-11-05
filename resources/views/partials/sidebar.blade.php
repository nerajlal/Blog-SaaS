<aside class="fixed top-0 left-0 bottom-0 w-64 bg-white border-r border-slate-200 hidden md:flex flex-col shadow-sm z-40">
    <div class="h-16 flex items-center px-6 border-b border-slate-100 flex-shrink-0">
        <div class="w-8 h-8 bg-gradient-to-tr from-brand-500 to-blue-500 rounded-lg mr-3 flex items-center justify-center shadow-md shadow-brand-500/20">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
        </div>
        <span class="font-extrabold text-lg tracking-wide text-slate-900 uppercase">BlogPanel</span>
    </div>
    <nav class="flex-1 py-6 px-4 space-y-2 overflow-y-auto">
        <a href="{{ route('admin.projects') }}" class="flex items-center px-4 py-3 bg-brand-50 text-brand-600 rounded-xl transition-colors border border-brand-200 shadow-sm">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            <span class="font-bold text-sm uppercase tracking-wider">Projects</span>
        </a>
        <a href="#" class="flex items-center px-4 py-3 text-slate-500 hover:bg-slate-50 hover:text-slate-900 rounded-xl transition-colors">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-bold text-sm uppercase tracking-wider">Settings</span>
        </a>
    </nav>
    <div class="p-4 border-t border-slate-100 flex-shrink-0">
        <form action="{{ route('admin.logout') }}" method="POST">
            @csrf
            <button type="submit" class="w-full flex items-center px-4 py-2 text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg transition-colors text-left">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                <span class="font-bold text-sm uppercase tracking-wider">Log out</span>
            </button>
        </form>
    </div>
</aside>
