<?php

declare(strict_types=1);

namespace Tests\Unit\Examples\DocumentCatalog;

use Examples\DocumentCatalog\Document;
use Examples\DocumentCatalog\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testDetailEscapesEveryEditableFieldAndDescribesDownload(): void
    {
        $response = new Html()->detail($this->document());
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<html lang="ru">', $body);
        self::assertStringContainsString('href="#main"', $body);
        self::assertStringContainsString('<main id="main" tabindex="-1">', $body);
        self::assertStringContainsString('&lt;script&gt;&quot;Title&quot;&lt;/script&gt;', $body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $body);
        self::assertStringContainsString('&quot;name&quot;&lt;.pdf', $body);
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('<img ', $body);
        self::assertStringContainsString('href="/documents/7/download"', $body);
        self::assertStringContainsString('PDF, 12 345 байт', $body);
        self::assertStringContainsString('<time datetime="2026-10-17">17.10.2026</time>', $body);
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testListRendersBoundedPageNavigationWithEscapedTitle(): void
    {
        $body = (string) new Html()->listing([$this->document()], 7)->getBody();

        self::assertStringContainsString('href="/documents/7"', $body);
        self::assertStringContainsString('href="/documents?before=7"', $body);
        self::assertStringContainsString('rel="next"', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>', $body);
    }

    public function testEmptyListHasAnExplanationAndNoNextPage(): void
    {
        $body = (string) new Html()->listing([], null)->getBody();

        self::assertStringContainsString('Опубликованных документов на этой странице нет.', $body);
        self::assertStringNotContainsString('rel="next"', $body);
    }

    public function testErrorsUseHtmlAndPublicMessages(): void
    {
        foreach ([400, 404, 405, 500] as $status) {
            $response = new Html()->error($status);

            self::assertSame($status, $response->getStatusCode());
            self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('<h1>', (string) $response->getBody());
            self::assertStringNotContainsString(__DIR__, (string) $response->getBody());
        }
    }

    private function document(): Document
    {
        return new Document(7, '<script>"Title"</script>', '<img src=x onerror=alert(1)>', 1_792_195_200, str_repeat('a', 64) . '.pdf', '"name"<.pdf', 12_345);
    }
}
