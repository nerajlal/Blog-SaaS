<?php
/** Standalone, server-rendered reference consumer. See this directory's README. */
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function api(string $path): array
{
    $base = rtrim(getenv('BLOGSAAS_URL') ?: 'http://127.0.0.1:8124', '/');
    $key = getenv('BLOGSAAS_API_KEY') ?: '';
    if (!$key || !preg_match('~^https?://~', $base) || preg_match('/[\r\n]/', $key)) {
        throw new RuntimeException('Configure BLOGSAAS_URL and BLOGSAAS_API_KEY on the server.');
    }
    $handle = curl_init($base.'/api/v1/projects/'.$path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: '.$key]]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($status === 404) {
        missingPage('Story not found.');
    }
    if ($body === false || $status !== 200) {
        throw new RuntimeException('The publishing service is unavailable or this project is not configured.');
    }
    return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
}

/** Best-effort, bounded server-to-server reporting; never delay a 404 by more than two seconds. */
function missingPage(string $message = 'Page not found.'): never
{
    $key = getenv('BLOGSAAS_TELEMETRY_KEY') ?: '';
    $origin = rtrim(getenv('PUBLIC_SITE_URL') ?: '', '/');
    if ($key && !preg_match('/[\r\n]/', $key) && preg_match('~^https://[a-z0-9.-]+$~i', $origin)) {
        try {
            $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
            $hex = bin2hex($bytes); $id = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $ref = parse_url($_SERVER['HTTP_REFERER'] ?? '');
            $referrer = is_array($ref) && isset($ref['scheme'],$ref['host']) && !isset($ref['user']) && !isset($ref['pass']) ? $ref['scheme'].'://'.$ref['host'].($ref['path'] ?? '/') : null;
            $handle = curl_init(rtrim(getenv('BLOGSAAS_URL') ?: 'http://127.0.0.1:8124', '/').'/api/v1/projects/not-found');
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT_MS => 2000, CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-Telemetry-Key: '.$key],
                CURLOPT_POSTFIELDS => json_encode(['origin' => $origin, 'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'event_id' => $id, 'referrer' => $referrer], JSON_THROW_ON_ERROR)]);
            curl_exec($handle); curl_close($handle);
        } catch (Throwable) { /* Missing-page responses remain available during telemetry outages. */ }
    }
    http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); exit($message);
}

function sitemapPage(int $page): array
{
    $base = rtrim(getenv('BLOGSAAS_URL') ?: 'http://127.0.0.1:8124', '/');
    $key = getenv('BLOGSAAS_API_KEY') ?: '';
    $handle = curl_init($base.'/api/v1/projects/sitemap.xml?page='.$page);
    $pages = 1;
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-API-KEY: '.$key], CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$pages) {
            if (preg_match('/^X-Sitemap-Pages:\s*(\d+)/i', $header, $match)) $pages = max(1, (int) $match[1]);
            return strlen($header);
        }]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false || $status !== 200) throw new RuntimeException('Verify the public origin and configure the sitemap connection.');
    return [$body, $pages];
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store'); // Keeps unpublishing and scheduled publication visible without stale cache.
try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $publicOrigin = rtrim(getenv('PUBLIC_SITE_URL') ?: '', '/');
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        $rule = api('redirect?path='.rawurlencode($path))['redirect'] ?? null;
        if ($rule) {
            $status = (int) $rule['status'];
            if ($status === 410) { http_response_code(410); exit('This page is permanently gone.'); }
            $location = $rule['location'] ?? '';
            if (!in_array($status, [301,302,307,308], true) || !preg_match('~^/(?!/)[a-z0-9/%_.\~-]*$~i', $location) || preg_match('/%(?:0[ad]|2f|5c)/i', $location)) throw new RuntimeException('Invalid redirect response.');
            header('Location: '.$location, true, $status); exit;
        }
    }
    if ($path === '/sitemap.xml' || preg_match('~^/blog-sitemap-(\d+)\.xml$~', $path, $matches)) {
        if (!preg_match('~^https://[a-z0-9.-]+$~i', $publicOrigin)) throw new RuntimeException('Set PUBLIC_SITE_URL to the verified public HTTPS origin.');
        [$xml, $pages] = sitemapPage(isset($matches[1]) ? max(1, (int) $matches[1]) : 1);
        header('Content-Type: application/xml; charset=UTF-8');
        if ($path !== '/sitemap.xml') {
            if ((int) $matches[1] > $pages) { http_response_code(404); exit; }
            echo $xml;
            exit;
        }
        $writer = new XMLWriter;
        $writer->openMemory(); $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex'); $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        for ($page = 1; $page <= $pages; $page++) {
            $writer->startElement('sitemap'); $writer->writeElement('loc', $publicOrigin.'/blog-sitemap-'.$page.'.xml'); $writer->endElement();
        }
        $writer->endElement(); $writer->endDocument(); echo $writer->outputMemory(); exit;
    }
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "User-agent: *\nAllow: /\n";
        if (preg_match('~^https://[a-z0-9.-]+$~i', $publicOrigin)) echo 'Sitemap: '.$publicOrigin."/sitemap.xml\n";
        exit;
    }
    if ($path === '/') {
        $page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
        $data = api('blogs?page='.$page);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($data['project']).'</title></head><body><main><h1>'.h($data['project']).'</h1>';
        foreach ($data['blogs'] as $blog) echo '<article><h2><a href="/blog/'.rawurlencode($blog['slug']).'">'.h($blog['title']).'</a></h2><p>'.h($blog['seo']['description']).'</p></article>';
        if ($page > 1) echo '<a href="?page='.($page - 1).'">Previous</a> ';
        if ($page < $data['meta']['last_page']) echo '<a href="?page='.($page + 1).'">Next</a>';
        echo '</main></body></html>'; exit;
    }
    if (!preg_match('~^/blog/([^/]+)/?$~', $path, $match)) { missingPage(); }
    $data = api('blogs/'.rawurlencode(rawurldecode($match[1])));
    $blog = $data['blog']; $seo = $blog['seo'];
    header('Content-Type: text/html; charset=UTF-8');
    // Trust only the configured BlogSaaS API. Its section HTML is sanitized by the publishing service.
    ?>
<!doctype html>
<html lang="<?= h($seo['locale']) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($seo['title']) ?></title>
<meta name="description" content="<?= h($seo['description']) ?>">
<meta name="robots" content="<?= $seo['robots']['index'] ? 'index' : 'noindex' ?>,<?= $seo['robots']['follow'] ? 'follow' : 'nofollow' ?>">
<?php if ($seo['canonical_url']): ?><link rel="canonical" href="<?= h($seo['canonical_url']) ?>"><?php endif ?>
<?php foreach (['title', 'description', 'type', 'site_name', 'url', 'image_url', 'image_alt'] as $field): if (!empty($seo['open_graph'][$field])): ?>
<meta property="og:<?= $field === 'image_url' ? 'image' : ($field === 'image_alt' ? 'image:alt' : $field) ?>" content="<?= h($seo['open_graph'][$field]) ?>">
<?php endif; endforeach ?>
<?php foreach (['card', 'title', 'description', 'image_url'] as $field): if (!empty($seo['twitter'][$field])): ?>
<meta name="twitter:<?= $field === 'image_url' ? 'image' : $field ?>" content="<?= h($seo['twitter'][$field]) ?>">
<?php endif; endforeach ?>
<?php if ($seo['schema']): ?><script type="application/ld+json"><?= json_encode($seo['schema'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script><?php endif ?>
<style>body{max-width:760px;margin:60px auto;padding:0 24px;color:#172329;font:18px/1.8 system-ui,sans-serif}h1{font-size:44px;line-height:1.15;letter-spacing:-1.5px}img{max-width:100%;height:auto;border-radius:16px}figcaption{font-size:14px;color:#64748b}a{color:#2563eb}pre{overflow:auto}header{margin-bottom:40px}figure{margin:30px 0}.ql-align-center{text-align:center}.ql-align-right{text-align:right}.ql-align-justify{text-align:justify}</style>
</head>
<body><header><a href="/">← <?= h($data['project']) ?></a></header><main><article>
<h1><?= h($blog['title']) ?></h1>
<?php if ($blog['author_name']): ?><p>By <?= h($blog['author_name']) ?></p><?php endif ?>
<?php if ($blog['cover_image_url']): ?><figure><img src="<?= h($blog['cover_image_url']) ?>" alt="<?= $blog['cover_image_decorative'] ? '' : h($blog['cover_image_alt']) ?>"><?php if ($blog['cover_image_caption']): ?><figcaption><?= h($blog['cover_image_caption']) ?></figcaption><?php endif ?></figure><?php endif ?>
<?php foreach ($blog['sections'] as $section): ?><section><?= $section['text_content'] ?><?php if ($section['image_url']): ?><figure><img src="<?= h($section['image_url']) ?>" alt="<?= $section['image_decorative'] ? '' : h($section['image_alt']) ?>"><?php if ($section['image_caption']): ?><figcaption><?= h($section['image_caption']) ?></figcaption><?php endif ?></figure><?php endif ?></section><?php endforeach ?>
</article></main></body></html>
<?php
} catch (Throwable $exception) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Retry-After: 60');
    echo 'The publication is temporarily unavailable. Check the server-side API connection and project configuration.';
}
