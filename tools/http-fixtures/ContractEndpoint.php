<?php

declare(strict_types=1);

namespace Project\HttpFixtures;

use App\Generated\Echo\EchoRequest;
use App\Generated\Echo\EchoResponse;
use App\Generated\Echo\EchoSendEndpoint;
use Examples\ContractEcho\EchoEndpoint;

final readonly class ContractEndpoint implements EchoSendEndpoint
{
    public function __construct(private EchoEndpoint $echo) {}

    public function handle(EchoRequest $request): EchoResponse
    {
        if ($request->message === 'wire-throw') {
            throw new \InvalidArgumentException('wire-private-contract-failure');
        }
        if ($request->message === 'wire-invalid-output') {
            return new EchoResponse("\xFF");
        }

        return $this->echo->handle($request);
    }
}
