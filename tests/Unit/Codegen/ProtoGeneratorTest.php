<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FileDescriptorProto;
use Google\Protobuf\Internal\FileDescriptorSet;
use Google\Protobuf\Internal\MethodOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Project\ProtoGen\Generator;
use Project\ProtoGen\Output;

final class ProtoGeneratorTest extends TestCase
{
    public function testCheckedInPhpMatchesTheDescriptorDeterministically(): void
    {
        $bytes = Output::read(\dirname(__DIR__, 3) . '/contracts/echo.pb');
        $generator = new Generator();
        $files = $generator->generate($bytes, 'echo.proto');
        self::assertCount(4, $files);
        self::assertSame($files, $generator->generate($bytes, 'echo.proto'));
        Output::check(\dirname(__DIR__, 3) . '/gen/Generated/Echo', $files);
    }

    /** @param \Closure(FileDescriptorProto): void $change */
    #[DataProvider('provideUnsupportedContractsFailBeforeWritingCases')]
    public function testUnsupportedContractsFailBeforeWriting(\Closure $change): void
    {
        $set = new FileDescriptorSet();
        $set->mergeFromString(Output::read(\dirname(__DIR__, 3) . '/contracts/echo.pb'));
        $change($set->getFile()[0]);
        $this->expectException(\InvalidArgumentException::class);
        new Generator()->generate($set->serializeToString(), 'echo.proto');
    }

    /** @return iterable<string, array{\Closure(FileDescriptorProto): void}> */
    public static function provideUnsupportedContractsFailBeforeWritingCases(): iterable
    {
        yield 'non-string' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[0]->getField()[0]->setType(5);
        }];
        yield 'repeated' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[0]->getField()[0]->setLabel(3);
        }];
        yield 'explicit presence' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[0]->getField()[0]->setProto3Optional(true);
        }];
        yield 'JSON alias' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[0]->getField()[0]->setJsonName('alias');
        }];
        yield 'nested' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[0]->setNestedType([new DescriptorProto()->setName('Nested')]);
        }];
        yield 'stream' => [static function (FileDescriptorProto $file): void {
            $file->getService()[0]->getMethod()[0]->setServerStreaming(true);
        }];
        yield 'no binding' => [static function (FileDescriptorProto $file): void {
            $file->getService()[0]->getMethod()[0]->setOptions(new MethodOptions());
        }];
        yield 'foreign message' => [static function (FileDescriptorProto $file): void {
            $file->getService()[0]->getMethod()[0]->setInputType('.foreign.Message');
        }];
        yield 'case collision' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[1]->setName('ECHOREQUEST');
        }];
        yield 'PHP keyword' => [static function (FileDescriptorProto $file): void {
            $file->getMessageType()[0]->setName('Match');
        }];
        yield 'duplicate route' => [static function (FileDescriptorProto $file): void {
            $method = clone $file->getService()[0]->getMethod()[0];
            $file->getService()[0]->getMethod()[] = $method->setName('Again');
        }];
    }

    public function testRegenerationRemovesRetiredRpcFilesAndDetectsStaleOutput(): void
    {
        $directory = sys_get_temp_dir() . '/zerno-proto-test-' . bin2hex(random_bytes(8));

        try {
            Output::replace($directory, ['OldEndpoint.php' => '<?php', 'OldHttp.php' => '<?php']);
            Output::replace($directory, ['NewEndpoint.php' => '<?php']);
            self::assertFileDoesNotExist($directory . '/OldHttp.php');
            Output::check($directory, ['NewEndpoint.php' => '<?php']);
            file_put_contents($directory . '/extra.php', '<?php');
            $this->expectException(\RuntimeException::class);
            Output::check($directory, ['NewEndpoint.php' => '<?php']);
        } finally {
            Output::remove($directory);
        }
    }

    public function testMultipleFieldsDoNotCollideWithDecoderVariables(): void
    {
        $set = new FileDescriptorSet();
        $set->mergeFromString(Output::read(\dirname(__DIR__, 3) . '/contracts/echo.pb'));
        $file = $set->getFile()[0];
        self::assertNotNull($file->getOptions());
        $file->getOptions()->setPhpNamespace('App\Generated\DecoderFixture');
        $file->getMessageType()[0]->getField()[0]->setName('input')->setJsonName('input');
        $file->getMessageType()[0]->getField()[] = new FieldDescriptorProto()->setName('json')->setJsonName('json')->setNumber(2)->setType(9)->setLabel(1);
        $files = new Generator()->generate($set->serializeToString(), 'echo.proto');
        $directory = sys_get_temp_dir() . '/zerno-decoder-' . bin2hex(random_bytes(8));

        try {
            Output::replace($directory, $files);
            require $directory . '/EchoRequest.php';
            $method = new \ReflectionMethod('App\Generated\DecoderFixture\EchoRequest', 'fromJson');
            $request = $method->invoke(null, '{"input":"first","json":"second"}');
            self::assertIsObject($request);
            self::assertSame('{"input":"first","json":"second"}', new \ReflectionMethod($request, 'toJson')->invoke($request));
        } finally {
            Output::remove($directory);
        }
    }
}
