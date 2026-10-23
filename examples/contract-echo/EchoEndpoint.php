<?php

declare(strict_types=1);

namespace Examples\ContractEcho;

use App\Generated\Echo\EchoRequest;
use App\Generated\Echo\EchoResponse;
use App\Generated\Echo\EchoSendEndpoint;

final readonly class EchoEndpoint implements EchoSendEndpoint
{
    public function handle(EchoRequest $request): EchoResponse
    {
        return new EchoResponse($request->message);
    }
}
