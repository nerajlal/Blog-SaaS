<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BlogSaaS - Headless Blog & Publishing Engine for Modern Teams</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-950 text-slate-100 antialiased selection:bg-indigo-500 selection:text-white">
    <!-- Navbar -->
    <header class="border-b border-slate-800/80 bg-slate-950/70 backdrop-blur-xl sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center shadow-lg shadow-indigo-500/25">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>
                    </svg>
                </div>
                <span class="font-extrabold text-xl tracking-tight text-white">Blog<span class="text-indigo-400">SaaS</span></span>
            </div>

            <nav class="hidden md:flex items-center space-x-8 text-sm font-medium text-slate-400">
                <a href="#features" class="hover:text-white transition">Features</a>
                <a href="#api" class="hover:text-white transition">Headless API</a>
                <a href="#pricing" class="hover:text-white transition">Pricing</a>
            </nav>

            <div class="flex items-center space-x-4">
                <a href="{{ route('login') }}" class="text-sm font-semibold text-slate-300 hover:text-white transition px-3 py-2">Sign In</a>
                <a href="{{ route('register') }}" class="text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-500 px-5 py-2.5 rounded-xl shadow-lg shadow-indigo-600/25 transition">
                    Get Started Free
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative pt-24 pb-20 px-6 overflow-hidden">
        <div class="max-w-4xl mx-auto text-center">
            <div class="inline-flex items-center px-4 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 text-xs font-semibold mb-6">
                ✨ Multi-tenant Content Management for modern teams
            </div>
            <h1 class="text-5xl md:text-6xl font-extrabold tracking-tight text-white mb-6 leading-tight">
                Launch, Manage & Scale Blogs <span class="bg-gradient-to-r from-indigo-400 via-violet-400 to-pink-400 bg-clip-text text-transparent">As A Service</span>
            </h1>
            <p class="text-lg md:text-xl text-slate-400 mb-10 max-w-2xl mx-auto leading-relaxed">
                Empower your organization to publish blog posts, organize categories, and deliver blazing-fast content to your custom frontend via our production-ready REST API.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="{{ route('register') }}" class="w-full sm:w-auto px-8 py-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-base shadow-xl shadow-indigo-600/30 transition transform hover:-translate-y-0.5">
                    Start Your 14-Day Free Trial
                </a>
                <a href="{{ route('login') }}" class="w-full sm:w-auto px-8 py-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-800 font-semibold text-base transition">
                    View Demo Dashboard
                </a>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="py-20 border-t border-slate-900 bg-slate-900/40 px-6">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl font-bold text-white mb-4">Built for developers and content teams</h2>
                <p class="text-slate-400">Everything you need to run high-converting publication hubs.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <div class="p-8 rounded-2xl bg-slate-900/60 border border-slate-800">
                    <div class="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center mb-6">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Isolated Workspaces</h3>
                    <p class="text-sm text-slate-400">Organize different clients, products, or brands with independent API keys and granular member permissions.</p>
                </div>

                <div class="p-8 rounded-2xl bg-slate-900/60 border border-slate-800">
                    <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center mb-6">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Instant Headless API</h3>
                    <p class="text-sm text-slate-400">Query your content seamlessly from Next.js, Nuxt, Astro, or mobile apps using secure API tokens.</p>
                </div>

                <div class="p-8 rounded-2xl bg-slate-900/60 border border-slate-800">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-6">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Custom Subdomains</h3>
                    <p class="text-sm text-slate-400">Each project receives an instant subdomain and support for custom root domain mapping.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-slate-900 py-10 text-center text-xs text-slate-500">
        <p>© 2026 BlogSaaS Platform. All rights reserved.</p>
    </footer>
</body>
</html>
