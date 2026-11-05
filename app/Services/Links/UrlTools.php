<?php

namespace App\Services\Links;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class UrlTools
{
    public function url(string $value, ?string $base = null): string
    {
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\s\\\\<>"\x00-\x1f\x7f]/u', $value)) throw new InvalidArgumentException('Use a normal public HTTP(S) URL.');
        try { $uri = $base ? UriResolver::resolve(new Uri($base), new Uri($value)) : new Uri($value); }
        catch (\Throwable) { throw new InvalidArgumentException('Invalid URL.'); }
        $scheme = strtolower($uri->getScheme()); $host = strtolower($uri->getHost());
        if (!in_array($scheme, ['https', 'http'], true) || $uri->getUserInfo() !== '' || $uri->getPort() !== null
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)
            || preg_match('/\.(localhost|local|internal|test|invalid)$/i', $host) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Only public hostnames on standard HTTP(S) ports are supported.');
        }
        return (string) $uri->withScheme($scheme)->withHost($host)->withFragment('')->withPath($uri->getPath() ?: '/');
    }

    public function origin(string $url): string
    {
        $uri = new Uri($this->url($url));
        return $uri->getScheme().'://'.$uri->getHost();
    }

    public function path(string $value, bool $stripQuery = false): string
    {
        if ($stripQuery) $value = preg_split('/[?#]/', $value, 2)[0];
        if ($value === '' || strlen($value) > 1000 || $value[0] !== '/' || str_contains($value, '//') || preg_match('/[?#\\\\\x00-\x20\x7f]/', $value) || preg_match('/%(?:2f|5c)/i', $value) || preg_match('/%(?![a-f0-9]{2})/i', $value)) throw new InvalidArgumentException('Use a rooted path without query strings, fragments, backslashes, encoded separators, or whitespace.');
        $decoded = rawurldecode($value);
        if (!mb_check_encoding($decoded, 'UTF-8') || preg_match('/[%?#\\\\\x00-\x20\x7f]/', $decoded)) throw new InvalidArgumentException('Invalid encoded path.');
        $segments = explode('/', $decoded);
        foreach ($segments as $segment) if (in_array($segment, ['.', '..'], true)) throw new InvalidArgumentException('Dot-segment paths are not supported.');
        return implode('/', array_map('rawurlencode', $segments));
    }

    public function publicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) if ($this->inRange($ip, $range)) return false;
            return true;
        }
        return $this->inRange($ip, '2000::/3') && !$this->inRange($ip, '2001::/23') && !$this->inRange($ip, '2001:db8::/32') && !$this->inRange($ip, '2002::/16') && !$this->inRange($ip, '3fff::/20');
    }

    private function inRange(string $ip, string $range): bool
    {
        [$network, $bits] = explode('/', $range); $ip = inet_pton($ip); $network = inet_pton($network);
        if ($ip === false || $network === false || strlen($ip) !== strlen($network)) return false;
        $bytes = intdiv((int) $bits, 8); $remaining = (int) $bits % 8;
        return substr($ip, 0, $bytes) === substr($network, 0, $bytes) && (!$remaining || (ord($ip[$bytes]) & (255 << (8 - $remaining))) === (ord($network[$bytes]) & (255 << (8 - $remaining))));
    }
}
