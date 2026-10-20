<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\AttachmentResponse;
use App\Platform\Http\Body;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

final readonly class DownloadHandler implements RequestHandler
{
    public function __construct(
        private CatalogRepository $repository,
        private FileStore $files,
        private Html $html,
    ) {}

    public function handle(Request $request): Response
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

            return $response->withBody(Body::create());
        }

        return $response;
    }
}
