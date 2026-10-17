<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\Handler\HandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DetailHandler implements HandlerInterface
{
    public function __construct(
        private CatalogRepository $repository,
        private Html $html,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('routeParams');
        $id = DocumentId::parse(\is_array($params) ? ($params['id'] ?? null) : null);
        $document = $id === null ? null : $this->repository->findPublished($id);

        return $document === null
            ? $this->html->error(404)
            : $this->html->detail($document);
    }
}
