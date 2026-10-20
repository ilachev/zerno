<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\Response;

final readonly class Html
{
    /** @param list<Document> $documents */
    public function listing(array $documents, ?int $next): Response
    {
        /** @var \Closure(list<Document>, ?int): void $template */
        $template = require __DIR__ . '/../templates/list.php';
        $content = self::capture(static fn() => $template($documents, $next));

        return $this->page('Документы', $content);
    }

    public function detail(Document $document): Response
    {
        /** @var \Closure(Document): void $template */
        $template = require __DIR__ . '/../templates/detail.php';
        $content = self::capture(static fn() => $template($document));

        return $this->page($document->title, $content);
    }

    public function error(int $status): Response
    {
        [$title, $message] = match ($status) {
            400 => ['Некорректный запрос', 'Проверьте адрес страницы и параметры запроса.'],
            404 => ['Документ не найден', 'Страница или документ отсутствует либо недоступен для публикации.'],
            405 => ['Метод не поддерживается', 'Для этой страницы доступны только указанные в ответе HTTP-методы.'],
            default => ['Ошибка сервера', 'Не удалось выполнить запрос. Попробуйте позже.'],
        };
        /** @var \Closure(string, string): void $template */
        $template = require __DIR__ . '/../templates/error.php';
        $content = self::capture(static fn() => $template($title, $message));

        return $this->page($title, $content, $status);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function fileSize(int $bytes): string
    {
        return number_format($bytes, 0, ',', ' ') . ' байт';
    }

    private function page(string $title, string $content, int $status = 200): Response
    {
        /** @var \Closure(string, string): void $template */
        $template = require __DIR__ . '/../templates/layout.php';
        $body = self::capture(static fn() => $template($title, $content));

        return new Response($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $body);
    }

    /** @param \Closure(): void $template */
    private static function capture(\Closure $template): string
    {
        ob_start();

        try {
            $template();
            $content = ob_get_contents();

            if ($content === false) {
                throw new \RuntimeException('The HTML output buffer is unavailable.');
            }

            return $content;
        } finally {
            ob_end_clean();
        }
    }
}
