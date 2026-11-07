<?php

namespace Tests\Unit;

use App\Services\Ai\WebsiteReader;
use App\Services\Links\UrlTools;
use PHPUnit\Framework\TestCase;

class AiWebsiteReaderTest extends TestCase
{
    private function reader(array $responses, array $ips = ['8.8.8.8']): WebsiteReader
    {
        return new class(new UrlTools, $responses, $ips) extends WebsiteReader
        {
            public array $visited = [];

            public function __construct($urls, private array $responses, private array $ips)
            {
                parent::__construct($urls);
            }

            protected function addresses(string $host): array
            {
                return $this->ips;
            }

            protected function request(string $url, string $ip): array
            {
                $this->visited[] = $url;
                if (! isset($this->responses[$url])) {
                    throw new \RuntimeException('Unexpected URL');
                }

                return $this->responses[$url];
            }
        };
    }

    private function response(string $body, int $status = 200, array $headers = []): array
    {
        return ['body' => $body, 'status' => $status, 'headers' => $headers + ['content-type' => 'text/html']];
    }

    public function test_private_dns_is_blocked_before_request(): void
    {
        $reader = $this->reader([], ['127.0.0.1']);
        try {
            $reader->read(['https://example.com/']);
            $this->fail('Private address accepted');
        } catch (\RuntimeException) {
            $this->assertSame([], $reader->visited);
        }
    }

    public function test_redirect_to_private_or_foreign_origin_is_not_followed(): void
    {
        $reader = $this->reader(['https://example.com/robots.txt' => $this->response('', 302, ['location' => 'http://169.254.169.254/latest/meta-data'])]);
        try {
            $reader->read(['https://example.com/']);
            $this->fail('Unsafe redirect accepted');
        } catch (\InvalidArgumentException|\RuntimeException) {
            $this->assertSame(['https://example.com/robots.txt'], $reader->visited);
        }
    }

    public function test_robots_blocks_redirected_paths_before_reading(): void
    {
        $reader = $this->reader([
            'https://example.com/robots.txt' => $this->response("User-agent: *\nDisallow: /private"),
            'https://example.com/' => $this->response('', 302, ['location' => '/private']),
        ]);
        try {
            $reader->read(['https://example.com/']);
            $this->fail('Disallowed redirect accepted');
        } catch (\RuntimeException) {
            $this->assertSame(['https://example.com/robots.txt', 'https://example.com/'], $reader->visited);
        }
    }

    public function test_extracts_readable_text_and_source_identity(): void
    {
        $reader = $this->reader([
            'https://example.com/robots.txt' => $this->response('', 404),
            'https://example.com/' => $this->response('<html><nav>Ignore navigation</nav><script>SECRET_SCRIPT</script><main><h1>Fresh scents</h1><p>'.str_repeat('Sample perfume facts. ', 100).'</p></main></html>'),
        ]);
        $sources = $reader->read(['https://example.com/']);
        $this->assertSame('web-1', $sources[0]['id']);
        $this->assertSame('https://example.com/', $sources[0]['url']);
        $this->assertStringContainsString('Fresh scents', $sources[0]['text']);
        $this->assertStringNotContainsString('SECRET_SCRIPT', $sources[0]['text']);
        $this->assertStringNotContainsString('Ignore navigation', $sources[0]['text']);
    }

    public function test_robots_longest_rule_and_explicit_allow(): void
    {
        $reader = new WebsiteReader(new UrlTools);
        $rules = "User-agent: *\nDisallow: /shop\nAllow: /shop/public\nDisallow: /*?secret=*";
        $this->assertFalse($reader->permitted($rules, 'https://example.com/shop/private'));
        $this->assertTrue($reader->permitted($rules, 'https://example.com/shop/public'));
        $this->assertFalse($reader->permitted($rules,'https://example.com/x?secret=key'));
    }
}
