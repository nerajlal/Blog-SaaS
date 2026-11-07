<?php

namespace App\Services\Ai;

use App\Services\Links\UrlTools;
use DOMDocument;
use DOMXPath;
use RuntimeException;

class WebsiteReader
{
    public function __construct(private UrlTools $urls) {}

    protected function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
    }

    protected function request(string $url, string $ip): array
    {
        $body = '';
        $headers = [];
        $headerBytes = 0;
        $host = parse_url($url, PHP_URL_HOST);
        $port = str_starts_with($url, 'https:') ? 443 : 80;
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'BlogSaaS-AI/1.0',
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers, &$headerBytes) {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) {
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                }
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 524288) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false) {
            throw new RuntimeException('Website fetch failed or exceeded its limit.');
        }

        return compact('body', 'headers', 'status');
    }

    private function fetch(string $url, string $origin, float $deadline, ?string $robots = null): array
    {
        for ($redirects = 0; $redirects <= 2; $redirects++) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Website time limit reached.');
            }
            $url = $this->urls->url($url);
            if ($this->urls->origin($url) !== $origin) {
                throw new RuntimeException('Redirect left the selected website. Enter the final website URL instead.');
            }
            if ($robots !== null && ! $this->permitted($robots, $url)) {
                throw new RuntimeException('Page is disallowed by robots.txt.');
            }
            $ips = $this->addresses(parse_url($url, PHP_URL_HOST));
            if (! $ips) {
                throw new RuntimeException('Website DNS could not be resolved.');
            }
            foreach ($ips as $ip) {
                if (! $this->urls->publicIp($ip)) {
                    throw new RuntimeException('Website resolves to a non-public address.');
                }
            }
            $response = $this->request($url, $ips[0]);
            if (in_array($response['status'], [301, 302, 303, 307, 308])) {
                $url = $this->urls->url($response['headers']['location'] ?? '', $url);

                continue;
            }

            return $response + ['url' => $url];
        }
        throw new RuntimeException('Website redirects too often.');
    }

    public function permitted(string $robots, string $url): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        foreach (preg_split('/\r?\n/', $robots) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if ($rules) {
                    $groups[] = [$agents, $rules];
                    $agents = [];
                    $rules = [];
                }
                $agents[] = strtolower($value);
            } elseif (in_array($key, ['allow', 'disallow']) && $agents && $value !== '') {
                $rules[] = [$key, $value];
            }
        }
        $groups[] = [$agents, $rules];
        $best = -1;
        $allowed = true;
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if (parse_url($url, PHP_URL_QUERY)) {
            $path .= '?'.parse_url($url, PHP_URL_QUERY);
        }
        foreach ($groups as [$agents, $rules]) {
            if (! in_array('*', $agents) && ! array_filter($agents, fn ($a) => $a !== '' && str_contains('blogsaas-ai', $a))) {
                continue;
            }
            foreach ($rules as [$key, $pattern]) {
                $end = str_ends_with($pattern, '$');
                $pattern = rtrim($pattern, '$');
                $regex = '~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).($end ? '$' : '').'~';
                if (preg_match($regex, $path) && (strlen($pattern) > $best || (strlen($pattern) === $best && $key === 'allow'))) {
                    $best = strlen($pattern);
                    $allowed = $key === 'allow';
                }
            }
        }

        return $allowed;
    }

    public function read(array $urls): array
    {
        if (! $urls || count($urls) > 3) {
            throw new RuntimeException('Choose one to three pages.');
        }
        $origin = $this->urls->origin($urls[0]);
        $deadline = microtime(true) + 22;
        $robots = $this->fetch($origin.'/robots.txt', $origin, $deadline);
        if ($robots['status'] !== 404 && $robots['status'] !== 410 && $robots['status'] !== 200) {
            throw new RuntimeException('Cannot confirm website crawl permissions. Paste source text instead.');
        }
        if ($robots['status'] === 200 && preg_match('/<html|<!doctype/i', $robots['body'])) {
            throw new RuntimeException('Website crawl policy could not be read.');
        }
        $policy = $robots['status'] === 200 ? $robots['body'] : '';
        $sources = [];
        foreach ($urls as $index => $url) {
            $url = $this->urls->url($url);
            if ($this->urls->origin($url) !== $origin || ! $this->permitted($policy, $url)) {
                throw new RuntimeException('A selected page is outside this website or disallowed by robots.txt.');
            }
            $response = $this->fetch($url, $origin, $deadline, $policy);
            // Check final paths too. Requests remain on the same vetted public origin.
            if (! $this->permitted($policy, $response['url'])) {
                throw new RuntimeException('The final page is disallowed by robots.txt.');
            }
            if ($response['status'] !== 200 || ! str_contains(strtolower($response['headers']['content-type'] ?? ''), 'text/html')) {
                throw new RuntimeException('Page is inaccessible or not HTML. Paste source text instead.');
            }
            $doc = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $doc->loadHTML('<?xml encoding="UTF-8">'.$response['body'], LIBXML_NONET);
                $xpath = new DOMXPath($doc);
                foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header|//form|//svg|//template') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $text = trim(preg_replace('/\s+/u', ' ', $doc->textContent) ?? '');
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (mb_strlen($text) < 60) {
                throw new RuntimeException('Page contains too little readable text. Paste source text instead.');
            }
            $sources[] = ['id' => 'web-'.($index + 1), 'url' => $response['url'], 'retrieved_at' => now()->toIso8601String(), 'text' => mb_strcut($text, 0, 10000, 'UTF-8')];
        }

        return $sources;
    }
}
