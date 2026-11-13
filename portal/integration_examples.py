"""Copyable examples for the current read-only published-content API."""

import json
import shlex


def examples(origin, key):
    base = origin.rstrip("/") + "/api/v1/projects/blogs"
    js_base, js_key = [
        json.dumps(value).replace("<", "\\u003c") for value in (base, key)
    ]
    curl = f"curl --fail-with-body {shlex.quote(base + '?page=1&per_page=12')} \\\n  -H {shlex.quote('X-API-KEY: ' + key)}"
    react = """import { useEffect, useState } from 'react';

const API = __BASE__;
const KEY = __KEY__;

export async function getPost(slug) {
  const response = await fetch(API + '/' + encodeURIComponent(slug), {
    headers: { 'X-API-KEY': KEY }
  });
  if (!response.ok) throw new Error('Post unavailable');
  return (await response.json()).blog;
}

export default function Posts() {
  const [posts, setPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController();
    fetch(API + '?page=1&per_page=12', {
      headers: { 'X-API-KEY': KEY }, signal: controller.signal
    }).then(async response => {
      if (!response.ok) throw new Error('Posts unavailable');
      return response.json();
    }).then(data => setPosts(data.blogs))
      .catch(error => { if (error.name !== 'AbortError') setError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, []);
  if (loading) return <p>Loading posts…</p>;
  if (error) return <p role="alert">{error}</p>;
  return <div>{posts.length ? posts.map(post => <article key={post.id}>
    <h2>{post.title}</h2><p>{post.excerpt}</p>
    {/* Connect this URL to your own post-detail route. */}
    <a href={'/posts/' + encodeURIComponent(post.slug)}>Read post</a>
  </article>) : <p>No published posts yet.</p>}</div>;
}""".replace("__BASE__", js_base).replace("__KEY__", js_key)
    php_base = "'" + base.replace("\\", "\\\\").replace("'", "\\'") + "'"
    php_key = "'" + key.replace("\\", "\\\\").replace("'", "\\'") + "'"
    wordpress = """// Run on the WordPress server, inside your plugin or child theme.
$response = wp_safe_remote_get(__BASE__ . '?page=1&per_page=12', array(
    'timeout' => 15,
    'redirection' => 0,
    'headers' => array('X-API-KEY' => __KEY__)
));
if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    return '<p>Posts are temporarily unavailable.</p>';
}
$data = json_decode(wp_remote_retrieve_body($response), true);
if (!is_array($data) || !isset($data['blogs'])) {
    return '<p>Invalid posts response.</p>';
}
$html = '';
foreach ($data['blogs'] as $post) {
    $html .= '<article><h2>' . esc_html($post['title']) . '</h2>';
    $html .= '<p>' . esc_html($post['excerpt']) . '</p></article>';
}
return $html ?: '<p>No published posts yet.</p>';""".replace(
        "__BASE__", php_base
    ).replace("__KEY__", php_key)
    shopify = """<div id="writepost-posts" aria-live="polite">Loading posts…</div>
<script>
(async () => {
  const root = document.getElementById('writepost-posts');
  const API = __BASE__;
  const KEY = __KEY__;
  const query = new URLSearchParams(location.search);
  const slug = query.get('twpost');
  const page = Math.max(1, Number.parseInt(query.get('twpage') || '1', 10) || 1);
  const endpoint = slug ? API + '/' + encodeURIComponent(slug)
                        : API + '?page=' + page + '&per_page=12';
  try {
    const response = await fetch(endpoint, { headers: { 'X-API-KEY': KEY } });
    if (!response.ok) throw new Error('Posts unavailable');
    const data = await response.json();
    root.replaceChildren();
    function text(tag, value, parent) {
      const element = document.createElement(tag);
      element.textContent = value || '';
      parent.append(element);
      return element;
    }
    function image(url, alt, caption, parent) {
      if (!url) return;
      const parsed = new URL(url, API);
      if (!['https:', 'http:'].includes(parsed.protocol)) return;
      const figure = document.createElement('figure');
      const img = document.createElement('img');
      img.src = parsed.href; img.alt = alt || '';
      img.style.maxWidth = '100%'; img.loading = 'lazy';
      figure.append(img); text('figcaption', caption, figure); parent.append(figure);
    }
    if (slug) {
      const post = data.blog;
      const back = text('a', '← All posts', root); back.href = location.pathname;
      text('h2', post.title, root); text('p', post.author_name, root);
      image(post.cover_image_url, post.cover_image_alt, post.cover_image_caption, root);
      // Only use HTML returned by your trusted WritePost API, which sanitizes it.
      const content = document.createElement('div'); content.innerHTML = post.content;
      root.append(content);
      for (const section of post.sections) {
        image(section.image_url, section.image_alt, section.image_caption, root);
        const body = document.createElement('div'); body.innerHTML = section.text_content;
        root.append(body);
      }
    } else {
      for (const post of data.blogs) {
        const article = document.createElement('article');
        text('h2', post.title, article); text('p', post.excerpt, article);
        const link = text('a', 'Read post', article);
        link.href = location.pathname + '?twpost=' + encodeURIComponent(post.slug);
        root.append(article);
      }
      if (!data.blogs.length) text('p', 'No published posts yet.', root);
      if (data.meta.current_page > 1) {
        const previous = text('a', '← Previous ', root);
        previous.href = location.pathname + '?twpage=' + (data.meta.current_page - 1);
      }
      if (data.meta.current_page < data.meta.last_page) {
        const next = text('a', 'Next →', root);
        next.href = location.pathname + '?twpage=' + (data.meta.current_page + 1);
      }
    }
  } catch (error) { root.textContent = 'Posts are temporarily unavailable.'; }
})();
</script>""".replace("__BASE__", js_base).replace("__KEY__", js_key)
    return {
        "base": base,
        "listing": base + "?page=1&per_page=12",
        "detail": base + "/{slug}",
        "curl": curl,
        "react": react,
        "wordpress": wordpress,
        "shopify": shopify,
    }
