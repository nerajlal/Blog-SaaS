<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Blog Admin Panel</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center relative overflow-hidden">
    <!-- Background decorations -->
    <div class="absolute top-[-10%] left-[-10%] w-96 h-96 bg-brand-400 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-blob"></div>
    <div class="absolute top-[20%] right-[-10%] w-96 h-96 bg-blue-400 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-blob animation-delay-2000"></div>
    <div class="absolute bottom-[-20%] left-[20%] w-96 h-96 bg-teal-400 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-blob animation-delay-4000"></div>

    <div class="w-full max-w-md p-8 relative z-10">
        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/50 border border-slate-100 p-8 md:p-10">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-gradient-to-tr from-brand-500 to-blue-500 rounded-2xl mx-auto mb-5 flex items-center justify-center shadow-lg shadow-brand-500/20">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight uppercase">Admin Portal</h1>
                <p class="text-slate-500 mt-2 font-medium">Sign in to manage your blogs.</p>
            </div>

            <form action="{{ route('admin.login.post') }}" method="POST" class="space-y-6">
                @csrf
                @if ($errors->any())
                    <div class="p-3 bg-red-50 border border-red-200 rounded-xl">
                        <ul class="text-sm text-red-600 font-medium">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div>
                    <label for="email" class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Email Address</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none transition-all text-slate-900 placeholder-slate-400 font-medium shadow-sm" placeholder="admin@example.com">
                </div>
                <div>
                    <label for="password" class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Password</label>
                    <input type="password" id="password" name="password" required class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none transition-all text-slate-900 placeholder-slate-400 font-medium shadow-sm" placeholder="••••••••">
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember-me" name="remember-me" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="remember-me" class="ml-2 block text-sm font-medium text-slate-600">Remember me</label>
                    </div>
                    <div class="text-sm">
                        <a href="#" class="font-bold text-brand-600 hover:text-brand-500 transition-colors">Forgot password?</a>
                    </div>
                </div>
                <button type="submit" class="w-full flex justify-center py-3.5 px-4 border border-transparent rounded-xl shadow-md shadow-brand-500/20 text-sm font-bold tracking-wider uppercase text-white bg-gradient-to-r from-brand-600 to-blue-600 hover:from-brand-500 hover:to-blue-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500 transition-all transform hover:scale-[1.02]">
                    Sign in to Dashboard
                </button>
            </form>
        </div>
    </div>
</body>
</html>
