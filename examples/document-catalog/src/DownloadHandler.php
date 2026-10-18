<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\AttachmentResponse;
use App\Platform\Http\Handler\HandlerInterface;
use Nyholm\Psr7\Stream;
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

        $response = AttachmentResponse::create(
            $body,
            $document->originalName,
            $document->fileSize,
            contentType: 'application/pdf',
            fallbackFilename: 'document-' . $document->id . '.pdf',
        )->withHeader('Cache-Control', 'no-store');

        if ($request->getMethod() === 'HEAD') {
            $body->close();

            return $response->withBody(Stream::create());
        }

        return $response;
    }
}
