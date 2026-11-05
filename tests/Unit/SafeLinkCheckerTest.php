<?php
namespace Tests\Unit;
use App\Services\Links\{SafeLinkChecker, UrlTools};
use PHPUnit\Framework\TestCase;

class SafeLinkCheckerTest extends TestCase
{
    private function checker(array $dns, array $responses): SafeLinkChecker
    {
        return new class(new UrlTools, $dns, $responses) extends SafeLinkChecker {
            public array $calls = [];
            public function __construct(UrlTools $urls, private array $dns, private array $responses) { parent::__construct($urls); }
            protected function addresses(string $host): array { return $this->dns[$host] ?? []; }
            protected function head(string $url, string $ip, int $milliseconds): array { $this->calls[] = [$url,$ip]; return array_shift($this->responses) + ['location' => null, 'error' => false, 'errno' => 0, 'large_headers' => false]; }
        };
    }
    public function test_address_policy_rejects_private_reserved_and_mapped_ipv6(): void
    {
        $urls = new UrlTools;
        foreach (['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','192.0.2.1','198.18.0.1','::1','::ffff:8.8.8.8','fc00::1','2001:db8::1','2002:0808:0808::1'] as $ip) $this->assertFalse($urls->publicIp($ip),$ip);
        foreach (['8.8.8.8','1.1.1.1','2606:4700:4700::1111'] as $ip) $this->assertTrue($urls->publicIp($ip),$ip);
    }
    public function test_mixed_dns_is_blocked_without_any_connection(): void
    {
        $checker = $this->checker(['example.com' => ['8.8.8.8','127.0.0.1']], []);
        $this->assertSame('blocked',$checker->check('https://example.com/a')['state']); $this->assertSame([],$checker->calls);
    }
    public function test_redirect_dns_is_revalidated_and_private_destination_never_connected(): void
    {
        $checker = $this->checker(['example.com' => ['8.8.8.8'], 'evil.example.com' => ['169.254.169.254']], [['code'=>302,'location'=>'https://evil.example.com/metadata']]);
        $this->assertSame('blocked',$checker->check('https://example.com/a')['state']); $this->assertCount(1,$checker->calls);
        $this->assertSame('8.8.8.8',$checker->calls[0][1]);
    }
    public function test_restricted_head_is_not_broken_and_temporary_failure_is_retryable(): void
    {
        foreach ([405=>'restricted',404=>'broken',410=>'broken',200=>'healthy',503=>'error'] as $code=>$state) {
            $checker = $this->checker(['example.com'=>['8.8.8.8']], [['code'=>$code]]); $result=$checker->check('https://example.com');
            $this->assertSame($state,$result['state']); $this->assertSame($code===503,$result['retryable']);
        }
    }
    public function test_redirect_loops_and_unsafe_schemes_are_bounded(): void
    {
        $checker = $this->checker(['example.com'=>['8.8.8.8']], [['code'=>301,'location'=>'/a']]);
        $this->assertSame('redirect_loop',$checker->check('https://example.com/a')['state']); $this->assertCount(1,$checker->calls);
        foreach (['file:///etc/passwd','https://user:password@example.com','http://127.0.0.1','https://example.com:8080','http://localhost/'] as $url) {
            $checker=$this->checker([],[]); $this->assertSame('blocked',$checker->check($url)['state']); $this->assertSame([],$checker->calls);
        }
    }
    public function test_path_normalization_rejects_encoded_separators_and_double_encoding(): void
    {
        $urls=new UrlTools;
        $this->assertSame('/caf%C3%A9',$urls->path('/café'));
        $this->assertSame('/post',$urls->path('/post?secret=value#x',true));
        foreach (['//evil.com','/a/../b','/%2fadmin','/%252fadmin','/a%0d%0aLocation:x','/a\\b'] as $path) {
            try {$urls->path($path);$this->fail('Unsafe path accepted: '.$path);} catch (\InvalidArgumentException) {$this->addToAssertionCount(1);}
        }
    }
}
