<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Feature;

use InertiaPixel\LaravelLogViewer\Tests\TestCase;

class SafeRenderingTest extends TestCase
{
    private const PAYLOADS = [
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '"><svg onload=alert(1)>',
        "'><iframe src=javascript:alert(1)>",
        '{{ 7*7 }} @php echo 1; @endphp',
    ];

    private function detailUrl(string $html, int $index = 0): string
    {
        preg_match_all('#data-ipx-lv-detail="([^"]+)"#', $html, $matches);

        return html_entity_decode($matches[1][$index]);
    }

    public function test_messages_context_and_traces_are_escaped(): void
    {
        $payload = implode(' ', self::PAYLOADS);
        $raw = $this->line('2026-10-15 10:00:00', 'error', $payload.' {"exception":"[object] (RuntimeException(code: 0): '.str_replace('"', '\\"', $payload).' at /app/x.php:1)');
        $raw = rtrim($raw)."\n[stacktrace]\n#0 /app/<script>alert(2)</script>.php(1): x()\n#1 {main}\n\"} \n";
        $raw .= $this->line('2026-10-15 10:01:00', 'info', 'ctx {"html":"<script>alert(3)</script>"}');
        $this->writeLog('laravel.log', $raw);

        $page = $this->actingAs($this->admin())->get('/log-viewer')->getContent();
        $detail = $this->get($this->detailUrl($page, 1), ['X-IPX-Log-Viewer' => 'fragment'])->getContent();
        $context = $this->get($this->detailUrl($page, 0), ['X-IPX-Log-Viewer' => 'fragment'])->getContent();

        foreach ([$page, $detail, $context] as $html) {
            $this->assertStringNotContainsString('<script>alert', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<svg onload', $html);
            $this->assertStringNotContainsString('<iframe', $html);
        }

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $detail);
        $this->assertStringContainsString('{{ 7*7 }}', html_entity_decode($page), 'Blade syntax in logs is shown, never evaluated.');
    }

    public function test_search_terms_are_escaped_when_echoed(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));

        $html = $this->actingAs($this->admin())->get('/log-viewer?q='.urlencode('"><script>alert(1)</script>'))->getContent();

        $this->assertStringNotContainsString('"><script>alert(1)', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function test_configured_secrets_are_redacted_everywhere(): void
    {
        config()->set('log-viewer.privacy.redaction.patterns', ['/cust_[a-z0-9]+/' => '[CUSTOMER]']);
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'error', 'Login for cust_abc123 failed {"password":"hunter2","token":"tok_live_99","headers":{"authorization":"Bearer abcdefghijklmnop"}}'));

        $page = $this->actingAs($this->admin())->get('/log-viewer')->getContent();
        $detail = $this->get($this->detailUrl($page), ['X-IPX-Log-Viewer' => 'fragment'])->getContent();

        foreach ([$page, $detail] as $html) {
            foreach (['hunter2', 'tok_live_99', 'abcdefghijklmnop', 'cust_abc123'] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }

        $this->assertStringContainsString('Login for [CUSTOMER] failed', $page);
        $this->assertStringContainsString('[REDACTED]', $detail);
    }

    public function test_list_and_object_values_of_sensitive_keys_are_masked(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'Request {"headers":{"cookie":["laravel_session=SESSIONSECRET"],"authorization":["Bearer TOKENSECRET123"]},"auth":{"pass":"PASSSECRET"},"password":"truncated-secret'));

        $page = $this->actingAs($this->admin())->get('/log-viewer')->getContent();
        $detail = $this->get($this->detailUrl($page), ['X-IPX-Log-Viewer' => 'fragment'])->getContent();

        foreach (['SESSIONSECRET', 'TOKENSECRET123', 'PASSSECRET', 'truncated-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
            $this->assertStringNotContainsString($secret, $detail);
        }
    }

    public function test_search_cannot_reveal_masked_values(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'Login {"password":"hunter2","cvv":"737"}'));
        $this->actingAs($this->admin());

        foreach (['hunter2', '"password":"hun', '"cvv":"737"', 'hunter'] as $guess) {
            $this->get('/log-viewer/results?q='.urlencode($guess))->assertSee('Nothing matches the current filters');
        }

        $this->get('/log-viewer/results?q=Login')->assertDontSee('Nothing matches the current filters');
    }

    public function test_entry_fingerprints_do_not_depend_on_masked_values(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'Payment {"cvv":"111"}').$this->line('2026-10-15 10:00:00', 'info', 'Payment {"cvv":"999"}'));

        $page = $this->actingAs($this->admin())->get('/log-viewer')->getContent();
        preg_match_all('#[?&]h=([a-f0-9]+)#', html_entity_decode($page), $matches);

        $this->assertCount(2, array_unique(array_map(fn ($url) => $url, $this->allDetailUrls($page))));
        $this->assertSame(1, count(array_unique($matches[1])), 'Entries differing only in a masked value share a fingerprint.');
        $this->assertSame(16, strlen($matches[1][0]));
    }

    private function allDetailUrls(string $html): array
    {
        preg_match_all('#data-ipx-lv-detail="([^"]+)"#', $html, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }

    public function test_invalid_utf8_does_not_break_rendering(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', "bad \xC3\x28 bytes \xFF here {\"k\":\"\xE2\x82\"}"));

        $page = $this->actingAs($this->admin())->get('/log-viewer');
        $page->assertOk()->assertSee('bad');

        $this->get($this->detailUrl($page->getContent()), ['X-IPX-Log-Viewer' => 'fragment'])->assertOk();
    }
}
