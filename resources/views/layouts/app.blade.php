<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Your workspace — BlogSaaS')</title>
    @include('partials.product-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="workspace-app">
<a class="product-skip" href="#workspace-content">Skip to content</a>
<button id="sidebar-backdrop" aria-label="Close workspace navigation" class="hidden fixed inset-0 bg-slate-950/40 z-30 md:hidden"></button>
@include('partials.sidebar', ['navigationProject' => request()->route('project') ?? request()->route('blog')?->project])
<main class="workspace-main">
    <header class="workspace-header">
        <div class="workspace-header-context"><button id="sidebar-toggle" type="button" aria-label="Open workspace navigation" aria-controls="workspace-sidebar" aria-expanded="false" class="workspace-menu-toggle md:hidden">☰</button><div class="workspace-page-heading">@yield('header_left')</div></div>
        <div class="workspace-header-actions"><span class="workspace-top-note"><span class="product-status-dot"></span> Your publishing workspace</span><details class="account-menu"><summary aria-label="Account menu"><span class="product-avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="account-chevron" aria-hidden="true">⌄</span></summary><div class="account-dropdown"><strong>{{ auth()->user()->name }}</strong><p>{{ auth()->user()->email }}</p><form action="{{ route('logout') }}" method="POST">@csrf<button type="submit">Sign out ↗</button></form></div></details></div>
    </header>
    <div id="workspace-content" class="workspace-content" tabindex="-1">
        @if(session('success'))<div role="status" class="product-notice notice-success workspace-notice"><span>✓ &nbsp; {{ session('success') }}</span><button type="button" data-dismiss-notice aria-label="Dismiss notification">×</button></div>@endif
        @if($errors->any())<div role="alert" class="product-notice notice-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('error'))<div role="alert" class="product-notice notice-error">{{ session('error') }}</div>@endif
        @yield('content')
        <footer class="workspace-footer"><span>A home for your ideas.</span><span>Made with a little possibility. <span aria-hidden="true">↗</span></span></footer>
    </div>
</main>
@stack('scripts')
</body>
</html>
