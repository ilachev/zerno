<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use PHPUnit\Framework\TestCase;
use Project\ProtoGen\Compiler;
use Project\ProtoGen\Output;

final class ProtoCompilerTest extends TestCase
{
    public function testCompilerUsesLiteralArgumentsAndCapturesFailure(): void
    {
        $directory = $this->directory();

        try {
            $executable = $directory . '/compiler';
            file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\nfwrite(STDERR, implode('|', array_slice(\$argv, 1))); exit(3);\n");
            chmod($executable, 0o700);
            $input = $directory . '/input;literal.proto';
            file_put_contents($input, '');

            try {
                new Compiler($directory)->compile($input, $executable);
                self::fail('Failed compiler must not return a descriptor');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('protoc failed:', $exception->getMessage());
                self::assertStringContainsString('input;literal.proto', $exception->getMessage());
                self::assertStringContainsString('--proto_path=' . $directory, $exception->getMessage());
            }
        } finally {
            Output::remove($directory);
        }
    }

    public function testHangingCompilerIsTerminated(): void
    {
        $directory = $this->directory();

        try {
            $executable = $directory . '/compiler';
            file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\nsleep(30);\n");
            chmod($executable, 0o700);
            $input = $directory . '/input.proto';
            file_put_contents($input, '');
            $start = hrtime(true);

            try {
                new Compiler($directory)->compile($input, $executable, 0.1);
                self::fail('Compiler timeout must fail');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('time or output limit', $exception->getMessage());
                self::assertLessThan(3_000_000_000, hrtime(true) - $start);
            }
        } finally {
            Output::remove($directory);
        }
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/zerno-compiler-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));

        return $directory;
    }

    public function testExcessiveCompilerDiagnosticsAreBounded(): void
    {
        $directory = $this->directory();

        try {
            $executable = $directory . '/compiler';
            file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\nfwrite(STDERR, str_repeat('x', 500000));\n");
            chmod($executable, 0o700);
            $input = $directory . '/input.proto';
            file_put_contents($input, '');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('time or output limit');
            new Compiler($directory)->compile($input, $executable);
        } finally {
            Output::remove($directory);
        }
    }
}
