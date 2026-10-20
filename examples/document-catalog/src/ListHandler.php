<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\RequestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListHandler implements RequestHandler
{
    public function __construct(
        private CatalogRepository $repository,
        private Html $html,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $before = null;

        if (\array_key_exists('before', $query)) {
            $before = DocumentId::parse($query['before']);

            if ($before === null) {
                return $this->html->error(400);
            }
        }

        $documents = $this->repository->page($before);
        $next = null;

        if (\count($documents) > 20) {
            $documents = \array_slice($documents, 0, 20);
            $next = $documents[19]->id;
        }

        return $this->html->listing($documents, $next);
    }
}
