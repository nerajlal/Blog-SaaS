<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reset ? 'Reset password' : 'Forgot password' }} — BlogSaaS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-5 text-slate-800">
    <main class="w-full max-w-md bg-white border border-slate-200 rounded-3xl p-8 shadow-sm">
        <a href="{{ route('home') }}" class="text-blue-600 font-bold">BlogSaaS ↗</a>
        <h1 class="text-2xl font-bold mt-6">{{ $reset ? 'A fresh start.' : 'Forgot your password?' }}</h1>
        <p class="text-sm text-slate-500 mt-3">{{ $reset ? 'Choose a new password for your workspace.' : 'Enter your email and we’ll send you a reset link.' }}</p>
        @if(session('status'))<p role="status" class="mt-5 text-sm text-emerald-700">{{ session('status') }}</p>@endif
        @if($errors->any())<ul role="alert" class="mt-5 text-sm text-red-600">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <form action="{{ $reset ? route('password.update') : route('password.email') }}" method="POST" class="space-y-5 mt-6">
            @csrf
            @if($reset)<input type="hidden" name="token" value="{{ $token }}">@endif
            <div><label for="email" class="block text-sm font-medium mb-2">Email address</label><input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email', $email ?? '') }}" class="w-full rounded-xl border border-slate-300 px-4 py-3"></div>
            @if($reset)
            <div><label for="password" class="block text-sm font-medium mb-2">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required class="w-full rounded-xl border border-slate-300 px-4 py-3"></div>
            <div><label for="password-confirmation" class="block text-sm font-medium mb-2">Confirm password</label><input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required class="w-full rounded-xl border border-slate-300 px-4 py-3"></div>
            @endif
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-full py-3 font-semibold">{{ $reset ? 'Reset password' : 'Send reset link' }}</button>
        </form>
        <a class="block mt-6 text-center text-sm text-slate-500" href="{{ route('login') }}">Back to sign in</a>
    </main>
</body>
</html>
