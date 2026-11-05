<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Your Account - SaaS Blog Platform</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md my-8">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-indigo-600 text-white mb-4 shadow-lg shadow-indigo-500/30">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">Create your SaaS account</h1>
            <p class="text-sm text-slate-400 mt-1">Start publishing blogs and headless content in minutes</p>
        </div>

        <div class="bg-slate-800/80 backdrop-blur-md border border-slate-700/80 rounded-2xl p-8 shadow-2xl">
            @if ($errors->any())
                <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('register.post') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Full Name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="John Doe"
                        class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 text-white text-sm outline-none transition duration-150">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Workspace / Company Name</label>
                    <input type="text" name="company_name" value="{{ old('company_name') }}" placeholder="Acme Studio (Optional)"
                        class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 text-white text-sm outline-none transition duration-150">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Email Address</label>
                    <input type="email" name="email" value="{{ old('email') }}" required placeholder="you@domain.com"
                        class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 text-white text-sm outline-none transition duration-150">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Password</label>
                    <input type="password" name="password" required placeholder="Minimum 8 characters"
                        class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 text-white text-sm outline-none transition duration-150">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Confirm Password</label>
                    <input type="password" name="password_confirmation" required placeholder="Repeat password"
                        class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 text-white text-sm outline-none transition duration-150">
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full py-3.5 px-4 rounded-xl font-semibold text-sm bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-600/30 transition duration-150 active:scale-[0.98]">
                        Create Free Workspace
                    </button>
                </div>
            </form>

            <div class="mt-6 pt-6 border-t border-slate-700/60 text-center">
                <p class="text-sm text-slate-400">
                    Already have an account? 
                    <a href="{{ route('login') }}" class="font-medium text-indigo-400 hover:text-indigo-300 transition">Sign in</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>

