<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use PHPUnit\Framework\TestCase;
use Spiral\Goridge\Frame;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\PayloadFactory;
use Spiral\RoadRunner\Version;
use Spiral\RoadRunner\Worker;

final class RoadRunnerTransportTest extends TestCase
{
    public function testNativeWorkerTransfersBinaryFramesWithoutARegistryOrLogger(): void
    {
        $input = fopen('php://temp', 'w+b');
        $output = fopen('php://temp', 'w+b');
        self::assertIsResource($input);
        self::assertIsResource($output);

        try {
            $peer = new StreamRelay($output, $input);
            $relay = new StreamRelay($input, $output);
            $header = '{"method":"POST"}';
            $peer->send(new Frame($header . "request\0bytes", [\strlen($header)]));
            rewind($input);
            $worker = new Worker($relay, interceptSideEffects: false);
            $request = $worker->waitPayload();
            self::assertNotNull($request);
            self::assertSame($header, $request->header);
            self::assertSame("request\0bytes", $request->body);
            $worker->respond(new Payload("response\0bytes", '{"status":200}'), Frame::CODEC_PROTO);
            rewind($output);
            $frame = $peer->waitFrame();
            self::assertTrue($frame->hasFlag(Frame::CODEC_PROTO));
            $response = PayloadFactory::fromFrame($frame);
            self::assertSame("response\0bytes", $response->body);
            self::assertSame('{"status":200}', $response->header);
            self::assertSame('3.0.0', Version::current());
            self::assertSame('3.*', Version::constraint());
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
