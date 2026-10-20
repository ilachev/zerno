<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

final readonly class DetailHandler implements RequestHandler
{
    public function __construct(
        private CatalogRepository $repository,
        private Html $html,
    ) {}

    public function handle(Request $request): Response
    {
        $params = $request->getAttribute('routeParams');
        $id = DocumentId::parse(\is_array($params) ? ($params['id'] ?? null) : null);
        $document = $id === null ? null : $this->repository->findPublished($id);

        return $document === null
            ? $this->html->error(404)
            : $this->html->detail($document);
    }
}
