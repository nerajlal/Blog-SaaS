<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\Seo\DomainVerification;
use PHPUnit\Framework\TestCase;

class DomainVerificationTest extends TestCase
{
    private function project(): Project
    {
        $project = new Project(['name' => 'Journal', 'seo_settings' => ['public_url' => 'https://journal.example.com']]);
        $project->seo_domain_token = 'expected-token';
        return $project;
    }

    public function test_matches_exact_txt_record_at_the_expected_hostname(): void
    {
        $verifier = new class extends DomainVerification {
            public string $requested = '';
            protected function records(string $name): array
            {
                $this->requested = $name;
                return [['txt' => 'unrelated'], ['entries' => ['blogsaas=', 'expected-token']]];
            }
        };
        $this->assertTrue($verifier->matches($this->project()));
        $this->assertSame('_blogsaas-verification.journal.example.com', $verifier->requested);
    }

    public function test_rejects_wrong_tokens_and_handles_lookup_failures(): void
    {
        $wrong = new class extends DomainVerification {
            protected function records(string $name): array { return [['txt' => 'blogsaas=wrong-token']]; }
        };
        $failed = new class extends DomainVerification {
            protected function records(string $name): array { throw new \RuntimeException('DNS unavailable'); }
        };
        $this->assertFalse($wrong->matches($this->project()));
        $this->assertFalse($failed->matches($this->project()));
    }

    public function test_does_not_query_dns_before_configuration(): void
    {
        $verifier = new class extends DomainVerification {
            protected function records(string $name): array { throw new \LogicException('Should not query DNS'); }
        };
        $this->assertFalse($verifier->matches(new Project));
    }
}
