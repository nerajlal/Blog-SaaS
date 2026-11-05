<?php

namespace App\Services\Links;

class SafeLinkChecker
{
    public function __construct(private UrlTools $urls) {}

    protected function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
    }

    protected function head(string $url, string $ip, int $milliseconds): array
    {
        $host = parse_url($url, PHP_URL_HOST); $port = str_starts_with($url, 'https://') ? 443 : 80;
        $location = null; $headerBytes = 0; $tooLarge = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $milliseconds), CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'BlogSaaS-LinkCheck/1.0',
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$location, &$headerBytes, &$tooLarge) {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) { $tooLarge = true; return 0; }
                if (stripos($line, 'HTTP/') === 0) $location = null;
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            },
        ]);
        $ok = curl_exec($handle); $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $errno = curl_errno($handle); curl_close($handle);
        return ['code' => $code, 'location' => $location, 'error' => $ok === false, 'errno' => $errno, 'large_headers' => $tooLarge];
    }

    public function check(string $url): array
    {
        $deadline = microtime(true) + 15; $seen = []; $redirects = 0;
        $result = fn ($state, $code, $final, $detail, $retry = false) => ['state' => $state, 'http_status' => $code ?: null, 'final_url' => $final, 'detail' => $detail, 'retryable' => $retry];
        while (true) {
            try { $url = $this->urls->url($url); }
            catch (\Throwable) { return $result('blocked', null, null, 'Unsafe or unsupported URL; no request was made.'); }
            if (isset($seen[$url])) return $result('redirect_loop', null, $url, 'The remote URL redirects in a loop.');
            $seen[$url] = true;
            try { $ips = $this->addresses(parse_url($url, PHP_URL_HOST)); }
            catch (\Throwable) { $ips = []; }
            if (!$ips) return $result('error', null, $url, 'DNS lookup failed.', true);
            foreach ($ips as $ip) if (!$this->urls->publicIp($ip)) return $result('blocked', null, $url, 'DNS includes a non-public address; no connection was made.');
            $remaining = (int) (($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) return $result('error', null, $url, 'The link-check time budget expired.', true);
            try { $response = $this->head($url, $ips[0], min(6000, $remaining)); }
            catch (\Throwable) { return $result('error', null, $url, 'The HTTP check failed.', true); }
            if ($response['error']) return $result('error', null, $url, $response['large_headers'] ? 'Remote headers exceeded the 32 KB limit.' : 'Connection, TLS, or timeout error.', !$response['large_headers']);
            $code = $response['code'];
            if (in_array($code, [301,302,303,307,308], true) && $response['location']) {
                if ($redirects++ >= 3) return $result('redirect_limit', $code, $url, 'More than three redirects; not followed further.');
                try { $url = $this->urls->url($response['location'], $url); }
                catch (\Throwable) { return $result('blocked', $code, null, 'Unsafe redirect destination; not followed.'); }
                continue;
            }
            if (in_array($code, [404,410], true)) return $result('broken', $code, $url, 'The public destination reports missing or gone.');
            if (in_array($code, [401,403,405,406], true)) return $result('restricted', $code, $url, 'The server restricts access or HEAD requests. This is not proof of a broken link.');
            if ($code === 429 || $code >= 500 || !$code) return $result('error', $code, $url, 'Temporary upstream failure or rate limit.', true);
            if ($code >= 200 && $code < 300) return $result($redirects ? 'redirected' : 'healthy', $code, $url, $redirects ? 'The destination resolves after a redirect.' : 'HEAD request succeeded. Page content was not inspected.');
            return $result('unconfirmed', $code, $url, 'Unusual response; review manually.');
        }
    }
}
