<?php

declare(strict_types=1);

namespace Zerno\Baseline;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Storage\Storage;

final readonly class JsonHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"id":42,"value":"record-42"}');
    }
}

final readonly class SelectHandler implements RequestHandler
{
    public function __construct(
        private Storage $storage,
    ) {}

    public function handle(Request $request): Response
    {
        $parameters = $request->getAttribute('routeParams');
        if (!\is_array($parameters) || !\is_string($parameters['id'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,8}\z/', $parameters['id']) !== 1) {
            return new Response(400, ['Content-Type' => 'application/json'], '{"error":"Invalid ID"}');
        }
        $rows = $this->storage->query(
            'SELECT id, value FROM baseline_records WHERE id = :id',
            ['id' => (int) $parameters['id']],
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return new Response(404, ['Content-Type' => 'application/json'], '{"error":"Not found"}');
        }
        if ((!\is_int($row['id'] ?? null) && !\is_string($row['id'] ?? null)) || !\is_string($row['value'] ?? null)) {
            throw new \RuntimeException('Invalid baseline record.');
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode(
            ['id' => (int) $row['id'], 'value' => $row['value']],
            JSON_THROW_ON_ERROR,
        ));
    }
}
