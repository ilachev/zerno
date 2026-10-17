<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\Handler\HandlerInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DownloadHandler implements HandlerInterface
{
    public function __construct(
        private CatalogRepository $repository,
        private FileStore $files,
        private Html $html,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('routeParams');
        $id = DocumentId::parse(\is_array($params) ? ($params['id'] ?? null) : null);
        $document = $id === null ? null : $this->repository->findPublished($id);

        if ($document === null) {
            return $this->html->error(404);
        }

        $body = $this->files->open($document);

        if ($body === null) {
            return $this->html->error(404);
        }

        $fallback = 'document-' . $document->id . '.pdf';
        $name = str_replace(['/', '\\'], '_', $document->originalName);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '');

        if ($name === '') {
            $name = $fallback;
        }

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $document->fileSize,
            'Content-Disposition' => 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->getMethod() === 'HEAD') {
            $body->close();

            return new Response(200, $headers);
        }

        return new Response(200, $headers, $body);
    }
}
