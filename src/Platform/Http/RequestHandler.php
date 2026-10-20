<?php

declare(strict_types=1);

namespace App\Platform\Http;

interface RequestHandler
{
    public function handle(Request $request): Response;
}
