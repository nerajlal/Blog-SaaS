<?php

namespace Tests\Unit;

use App\Support\RichText;
use PHPUnit\Framework\TestCase;

class RichTextTest extends TestCase
{
    public function test_preserves_editor_formatting_and_unicode(): void
    {
        $html = '<h2>Hello 世界</h2><p><strong>Bold</strong> &amp; <em>italic</em></p><ol><li data-list="bullet">One</li></ol>';
        $this->assertSame($html, RichText::clean($html));
    }

    public function test_removes_active_content_and_dangerous_urls(): void
    {
        $html = '<p onclick="alert(1)" style="color:red">Safe</p><script>alert(1)</script><svg onload="alert(2)"></svg><a href="javascript:alert(1)">Bad</a><img src=x onerror=alert(3)><iframe src="https://evil.test"></iframe>';
        $this->assertSame('<p>Safe</p><a>Bad</a>', RichText::clean($html));
    }

    public function test_safe_links_keep_protection_and_cleaning_is_idempotent(): void
    {
        $html = RichText::clean('<a href="https://example.test/?a=1&amp;b=2" target="_blank">Read</a><p class="evil ql-align-center">Centered</p>');
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertSame($html, RichText::clean($html));
    }
}
