<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * E3.10d: forwarding headers are trusted only from configured proxies. A local review tunnel (127.0.0.1) makes links
 * and assets use the visitor's HTTPS address; without the setting, nobody can change them with forged headers.
 */
class TrustedProxiesTest extends TestCase
{
    /** @var array<string, string> */
    private const TUNNEL = ['Host' => 'proba.trycloudflare.com', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'proba.trycloudflare.com', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '203.0.113.5'];

    public function test_forwarding_headers_are_ignored_by_default(): void
    {
        $this->assertNull(config('app.trusted_proxies'));

        $page = $this->withHeaders(self::TUNNEL)->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('https://proba.trycloudflare.com', $page);
        $this->assertStringContainsString('action="http://', $page);
    }

    public function test_a_configured_local_tunnel_gets_https_links_and_the_visitor_address(): void
    {
        config(['app.trusted_proxies' => '127.0.0.1']);

        $response = $this->withHeaders(self::TUNNEL)->get('/login')->assertOk();

        $this->assertStringContainsString('action="https://proba.trycloudflare.com/login"', $response->getContent());
        $this->assertStringNotContainsString('http://proba.trycloudflare.com', $response->getContent());
        $this->assertSame('203.0.113.5', $response->baseRequest->ip());
    }

    public function test_headers_from_an_untrusted_address_are_ignored(): void
    {
        config(['app.trusted_proxies' => '10.0.0.9']);

        $page = $this->withHeaders(self::TUNNEL)->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('https://proba.trycloudflare.com', $page);
    }
}
