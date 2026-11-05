<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Your content, your frontend. Write, organize, and schedule blog posts in BlogSaaS, then deliver them to any website through a simple REST API.">
    <meta name="theme-color" content="#f7fafc">
    <meta property="og:title" content="BlogSaaS — Great stories. Any frontend.">
    <meta property="og:description" content="A focused publishing workspace for your next great story. Create content once and deliver it through a simple API.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    <link rel="canonical" href="{{ url('/') }}">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700&family=Plus+Jakarta+Sans:wght@500;600;650;700;750;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <script src="{{ asset('js/landing.js') }}" defer></script>
    <title>BlogSaaS — Great stories. Any frontend.</title>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header wrap">
    <a class="brand pill" href="{{ route('home') }}" aria-label="BlogSaaS home"><span class="brand-mark">b<span>·</span></span>Blog<span class="brand-light">SaaS</span></a>
    <nav class="desktop-nav pill" aria-label="Main navigation"><a class="active" href="#main">Home</a><a href="#features">Features</a><a href="#how-it-works">How it works</a><a href="#developers">For developers</a><a href="#faq">FAQs</a></nav>
    <div class="header-actions pill"><a href="{{ route('login') }}">Log in</a><a class="small-cta" href="{{ route('register') }}">Get started <span aria-hidden="true">↗</span></a></div>
    <button class="menu-toggle pill" type="button" aria-label="Open navigation" aria-controls="mobile-menu" aria-expanded="false"><span></span><span></span></button>
    <nav id="mobile-menu" class="mobile-menu" aria-label="Mobile navigation" hidden><a href="#features">Features</a><a href="#how-it-works">How it works</a><a href="#developers">For developers</a><a href="#faq">FAQs</a><a href="{{ route('login') }}">Log in</a><a href="{{ route('register') }}">Create an account ↗</a></nav>
</header>
<main id="main">
    <section class="hero">
        <div class="hero-glow" aria-hidden="true"></div>
        <div class="wrap hero-content">
            <div class="eyebrow hero-badge"><span class="status-dot"></span> A simpler way to publish</div>
            <h1>Great stories.<br><span class="gradient-text">Any frontend.</span></h1>
            <p class="hero-description">Give your ideas a home. Write, organize, and publish in one place.<br class="desktop-break"> Deliver your content to the website you already love.</p>
            <div class="button-row"><a class="button button-dark" href="{{ route('register') }}">Start creating <span aria-hidden="true">↗</span></a><a class="button button-gradient" href="#how-it-works">See how it works <span class="play-icon" aria-hidden="true">▷</span></a></div>
            <p class="hero-note">Your content. Your stack. Your creative freedom.</p>
            <div class="product-stage">
                <div class="floating-note note-published"><span class="check-circle">✓</span><div><strong>Ready when you are</strong><span>Write now. Publish on your schedule.</span></div></div>
                <div class="app-preview" aria-label="Illustrative preview of the BlogSaaS publishing workspace">
                    <div class="preview-top"><div class="window-dots"><i></i><i></i><i></i></div><span>your next great story starts here</span><span class="preview-lock">⌁ &nbsp; blogsaaS / workspace</span></div>
                    <div class="preview-body"><aside class="preview-sidebar"><div class="preview-brand"><span class="mini-mark">b·</span> BlogSaaS</div><div class="workspace-label">YOUR WORKSPACE</div><div class="workspace-item"><span class="workspace-avatar">S</span> Studio workspace <span>⌄</span></div><div class="preview-nav selected">▦ <span>Projects</span> <span>02</span></div><div class="preview-nav">▤ <span>All stories</span></div><div class="preview-sidebar-bottom"><span class="avatar">JD</span><div>Jamie Davis<small>Personal workspace</small></div></div></aside>
                    <div class="preview-main"><div class="preview-breadcrumb">Workspace <span>/</span> Studio journal</div><div class="preview-heading"><div><h2>Your ideas, in motion.</h2><p>A little inspiration. A lot of possibility.</p></div><span class="preview-new">+ &nbsp; New story</span></div><div class="preview-tabs"><span class="selected">All stories <b>3</b></span><span>Published</span><span>Drafts</span><span class="preview-search">⌕ &nbsp; Search stories</span></div>
                    <div class="story-grid"><article class="story-card"><div class="story-art art-one"><span>Ideas worth<br>putting out<br><em>into the world.</em></span><i class="art-orbit"></i></div><div class="story-info"><span class="tag tag-green">● Published</span><h3>A fresh perspective on<br>what comes next</h3><p>Inspiration <span>·</span> 4 min read</p></div></article><article class="story-card"><div class="story-art art-two"><div class="arch arch-back"></div><div class="arch arch-front"></div><span>CREATE<br>SOMETHING<br><b>meaningful.</b></span></div><div class="story-info"><span class="tag tag-blue">◷ Scheduled</span><h3>Make room for your<br>next big idea</h3><p>Studio notes <span>·</span> 3 min read</p></div></article><article class="story-card third-story"><div class="story-art art-three"><div class="art-lines"></div><span>Less noise.<br><em>More story.</em></span></div><div class="story-info"><span class="tag tag-gray">● Draft</span><h3>The art of keeping<br>things simple</h3><p>Behind the scenes <span>·</span> 5 min read</p></div></article></div><p class="preview-caption">An illustrative workspace. Your stories will make it yours.</p></div>
                    </div>
                </div>
                <div class="floating-note note-api"><span class="code-icon">{ }</span><div><strong>One API. Endless possibilities.</strong><span>Connect to your favorite frontend.</span></div><span class="connection-dot"></span></div>
            </div>
        </div>
    </section>
    <section class="stack-strip wrap" aria-label="Frontend compatibility"><span>ONE WORKSPACE. YOUR FAVORITE STACK.</span><div class="stack-names"><span>Next.js <i>↗</i></span><span class="astro">Astro</span><span>Nuxt <i>△</i></span><span>React <i>✳</i></span><span>Vue <i>∨</i></span><span>and whatever’s next <i>→</i></span></div></section>
    <section id="features" class="section features-section"><div class="wrap"><div class="section-intro"><div><span class="eyebrow section-label"><span class="tiny-dot"></span> MADE FOR YOUR FLOW</span><h2>Less managing.<br>More creating.</h2></div><p>A thoughtful home for your content, from the first spark to the moment you hit publish. Everything you need to keep moving.</p></div><div class="feature-grid">
        <article class="feature-card"><div class="feature-icon blue">▦</div><span class="feature-number">01</span><h3>A place for every project.</h3><p>Keep brands, clients, and side projects organized in dedicated workspaces. Give each project its own stories, categories, and API key.</p><a href="{{ route('register') }}">Find your focus <span>↗</span></a><div class="mini-workspaces" aria-hidden="true"><div><span class="project-avatar lavender">S</span><div>Studio journal<small>Your ideas, all together</small></div><span class="mini-count">12 stories</span></div><div><span class="project-avatar peach">N</span><div>The next chapter<small>A space to start something</small></div><span class="mini-count">4 stories</span></div></div></article>
        <article class="feature-card"><div class="feature-icon mint">✎</div><span class="feature-number">02</span><h3>From a blank page to a story.</h3><p>Bring your writing to life with rich text, images, and flexible sections. Add categories and author details to make every post feel complete.</p><a href="{{ route('register') }}">Make it your own <span>↗</span></a><div class="mini-editor" aria-hidden="true"><div class="editor-toolbar"><b>B</b><i>I</i><u>U</u><span>│</span><span>≡</span><span>↗</span></div><strong>It starts with an idea.</strong><div class="fake-line long"></div><div class="fake-line"></div><div class="fake-line short"></div><span class="editor-caret"></span></div></article>
        <article class="feature-card"><div class="feature-icon violet">◷</div><span class="feature-number">03</span><h3>Your timing. Your terms.</h3><p>Save a draft while inspiration takes shape. Publish when it is ready, or choose a future date and let your API deliver it at the right time.</p><a href="#how-it-works">Meet your publishing flow <span>↗</span></a><div class="mini-schedule" aria-hidden="true"><div><span class="calendar-icon">NOV<strong>19</strong></span><div>Something good is coming<small>Scheduled · 9:00 AM</small></div><span class="schedule-check">✓</span></div><div class="schedule-track"><span></span></div><p>Idea <span>Draft</span><b>Published ↗</b></p></div></article>
    </div></div></section>
    <section id="how-it-works" class="section workflow-section"><div class="wrap workflow-grid"><div><span class="eyebrow section-label"><span class="tiny-dot"></span> A SIMPLE WORKFLOW</span><h2>From your first idea<br>to your next launch.</h2><p class="section-copy">Your publishing process should make space for good work. Here’s how BlogSaaS keeps things simple.</p><a class="text-link" href="{{ route('register') }}">Create your workspace <span>↗</span></a></div><div class="steps"><article><span class="step-number">01</span><div><h3>Make yourself at home.</h3><p>Create an account and a workspace. Add a project for your publication, product, or client.</p></div></article><article><span class="step-number">02</span><div><h3>Put your ideas into words.</h3><p>Write your first story, add images, and pick a category. Save it for later or set a publishing date.</p></div></article><article><span class="step-number">03</span><div><h3>Connect. Publish. Repeat.</h3><p>Use your project’s API key to fetch published stories. Build the reading experience your audience deserves.</p></div></article></div></div></section>
    <section id="developers" class="section developer-section"><div class="wrap developer-grid"><div><span class="eyebrow section-label"><span class="tiny-dot"></span> CONTENT WITHOUT CONSTRAINTS</span><h2>Your frontend.<br><span class="gradient-text">Our publishing engine.</span></h2><p class="section-copy">Keep the stack you love. Fetch published posts, categories, and structured sections with a single API request. You decide how the story looks.</p><ul class="check-list"><li>Project-specific, read-only API keys</li><li>Structured JSON with rich text and image sections</li><li>Drafts stay private until you publish</li><li>Paginated responses for growing publications</li></ul><a class="text-link" href="{{ route('register') }}">Build something worth reading <span>↗</span></a></div><div class="code-window"><div class="code-header"><div><span class="code-file-icon">{ }</span> get-stories.js</div><button id="copy-code" type="button" aria-label="Copy API example">Copy <span aria-hidden="true">▢</span></button></div><div class="code-body"><span class="code-comment">// Good stories are just one request away.</span><pre><code id="api-example"><span class="code-purple">const</span> response = <span class="code-purple">await</span> <span class="code-blue">fetch</span>(
  <span class="code-green">'{{ url('/api/v1/projects/blogs') }}'</span>,
  {
    headers: {
      <span class="code-green">'X-API-KEY'</span>: <span class="code-green">'YOUR_PROJECT_KEY'</span>,
      <span class="code-green">'Accept'</span>: <span class="code-green">'application/json'</span>
    }
  }
);

<span class="code-purple">const</span> { blogs, meta } = <span class="code-purple">await</span> response.<span class="code-blue">json</span>();</code></pre><div class="code-response"><span class="response-dot"></span> 200 OK <span>application/json</span></div><pre class="json-example"><code>{
  <span class="code-blue">"project"</span>: <span class="code-green">"Your next chapter"</span>,
  <span class="code-blue">"blogs"</span>: [<span class="code-comment">/* your published stories */</span>],
  <span class="code-blue">"meta"</span>: { <span class="code-blue">"current_page"</span>: <span class="code-orange">1</span>, ... }
}</code></pre></div><div class="code-footer"><span>REST API</span><span>No frontend lock-in. Just content. ↗</span></div></div></div></section>
    <section id="faq" class="section faq-section"><div class="wrap faq-grid"><div><span class="eyebrow section-label"><span class="tiny-dot"></span> A LITTLE MORE CLARITY</span><h2>Good questions.<br>Straight answers.</h2><p class="section-copy">Getting to know your next publishing tool? Start here.</p><a class="text-link" href="#developers">Explore the API <span>↗</span></a></div><div class="faq-list">
        <details open><summary>What is a headless blog, exactly?<span aria-hidden="true">+</span></summary><p>It separates writing from presentation. You manage posts in BlogSaaS, and your website fetches the published content through an API. Your frontend controls how it looks.</p></details>
        <details><summary>Can I use it with my existing website?<span aria-hidden="true">+</span></summary><p>Yes. Any frontend that can make an HTTP request can use the API, including Next.js, Astro, Nuxt, and custom websites. Connect from your server or use the read-only API from your frontend.</p></details>
        <details><summary>Can I manage more than one blog?<span aria-hidden="true">+</span></summary><p>Yes. Create multiple projects and organize them in workspaces. Each project has its own posts, categories, and API key.</p></details>
        <details><summary>How does scheduled publishing work?<span aria-hidden="true">+</span></summary><p>Choose a future publication date in the editor. The API returns the post once that date arrives. If your website caches content, refresh or revalidate its cache to show new posts.</p></details>
        <details><summary>Do I need to be a developer?<span aria-hidden="true">+</span></summary><p>Writing and organizing posts does not require code. Connecting the API to your own website requires some development work. Your project dashboard includes integration examples.</p></details>
    </div></div></section>
    <section class="cta-section wrap"><div class="cta-orbit" aria-hidden="true"></div><span class="eyebrow">A FRESH CHAPTER STARTS HERE</span><h2>Your next great story<br>deserves to be out there.</h2><p>Make a little space for your next big idea.</p><a class="button button-white" href="{{ route('register') }}">Let’s get you publishing <span aria-hidden="true">↗</span></a><span class="cta-spark" aria-hidden="true">✳</span></section>
</main>
<footer class="site-footer wrap"><div class="footer-top"><div><a class="brand" href="{{ route('home') }}"><span class="brand-mark">b<span>·</span></span>Blog<span class="brand-light">SaaS</span></a><p>A home for your ideas.<br>A publishing engine for your next chapter.</p></div><div class="footer-links"><div><h3>Explore</h3><a href="#features">Features</a><a href="#how-it-works">How it works</a><a href="#developers">Headless API</a></div><div><h3>Get started</h3><a href="{{ route('register') }}">Create an account</a><a href="{{ route('login') }}">Log in</a><a href="#faq">Common questions</a></div></div></div><div class="footer-bottom"><span>© {{ date('Y') }} BlogSaaS. All rights reserved.</span><span>Made for ideas that go places. <span aria-hidden="true">↗</span></span></div></footer>
<div id="copy-status" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
