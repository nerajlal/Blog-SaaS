<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Your workspace — BlogSaaS')</title>
    @include('partials.product-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
<a class="product-skip" href="#auth-form">Skip to form</a>
<header class="auth-header">
    @include('partials.product-brand', ['brandClass' => 'product-brand-pill'])
    <div class="auth-header-link">@yield('auth_navigation')</div>
</header>
<main class="auth-main">
    <section class="auth-story" aria-label="A home for your stories">
        <p class="product-eyebrow"><span class="product-status-dot"></span> A little space for big ideas</p>
        <h2>Great stories.<br><span class="product-gradient-text">Start with you.</span></h2>
        <p class="auth-story-copy">Your ideas, your voice, your next chapter.<br>A thoughtful place to bring them all together.</p>
        <div class="auth-illustration" aria-hidden="true">
            <span class="auth-orbit orbit-one"></span><span class="auth-orbit orbit-two"></span>
            <div class="auth-paper">
                <div class="paper-top"><span class="paper-dots"><i></i><i></i><i></i></span><span>Your story, taking shape</span><span>↗</span></div>
                <div class="paper-cover"><span>Less noise.<br>More <em>possibility.</em></span><span class="paper-spark">✳</span></div>
                <div class="paper-body"><span class="paper-label">A NEW PERSPECTIVE</span><h3>Something worth sharing.</h3><p>It starts with a little curiosity.<br>And a space to make it your own.</p><div class="paper-lines"><i></i><i></i><i></i></div><div class="paper-footer"><span class="paper-avatar">Y</span><span>Written by you</span><span class="paper-draft">● Draft</span></div></div>
            </div>
            <div class="auth-floating-note"><span>✓</span><div>Make it yours.<small>One idea. Endless possibilities.</small></div></div>
        </div>
        <p class="auth-story-foot">Your content. Your stack. Your creative freedom. <span>↗</span></p>
    </section>
    <section id="auth-form" class="auth-form-panel" aria-labelledby="auth-title">
        <div class="auth-form-heading"><p class="product-eyebrow">@yield('auth_eyebrow', 'Your next chapter')</p><h1 id="auth-title">@yield('auth_heading')</h1><p>@yield('auth_description')</p></div>
        @if(session('status'))<div role="status" class="product-notice notice-success">{{ session('status') }}</div>@endif
        @if($errors->any())<div role="alert" class="product-notice notice-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('auth_form')
        @yield('auth_footer')
    </section>
</main>
<footer class="auth-footer"><span>© {{ date('Y') }} BlogSaaS</span><a href="{{ route('home') }}">Back to the good ideas <span aria-hidden="true">↗</span></a></footer>
</body>
</html>
