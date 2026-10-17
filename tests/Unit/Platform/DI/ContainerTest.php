<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DI;

use App\Platform\DI\Container;
use App\Platform\DI\ContainerException;
use App\Platform\DI\DIContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\DI\Fixtures\{
    Circular1,
    Dependency,
    Implementation1,
    Implementation2,
    NestedDependency,
    ParentDependency,
    ServiceWithBuiltinType,
    ServiceWithDefaultValue,
    ServiceWithDependency,
    ServiceWithNestedDependency,
    ServiceWithNoTypeHint,
    ServiceWithUnionType,
    SimpleClass,
    TestInterface
};

final class ContainerTest extends TestCase
{
    /**
     * @var DIContainer<object>
     */
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new DIContainer();
    }

    public function testSetAndGetService(): void
    {
        // Arrange
        $service = new \stdClass();
        $this->container->set(\stdClass::class, static fn() => $service);

        // Act
        $result = $this->container->get(\stdClass::class);

        // Assert
        self::assertSame($service, $result);
    }

    public function testServiceIsSingleton(): void
    {
        // Arrange
        $this->container->set(\stdClass::class, static fn() => new \stdClass());

        // Act
        $first = $this->container->get(\stdClass::class);
        $second = $this->container->get(\stdClass::class);

        // Assert
        self::assertSame($first, $second);
    }

    public function testBindInterface(): void
    {
        // Arrange
        $implementation = new Implementation1();
        $this->container->set(Implementation1::class, static fn() => $implementation);
        $this->container->bind(TestInterface::class, Implementation1::class);

        // Act
        $result = $this->container->get(TestInterface::class);

        // Assert
        self::assertSame($implementation, $result);
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        // Arrange
        $this->container->set(\stdClass::class, static fn() => new \stdClass());

        // Act & Assert
        self::assertTrue($this->container->has(\stdClass::class));
    }

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        // Act & Assert
        self::assertFalse($this->container->has(\stdClass::class));
    }

    public function testAutoWiringSimpleClass(): void
    {
        // Act
        $result = $this->container->get(SimpleClass::class);

        // Assert
        self::assertInstanceOf(SimpleClass::class, $result);
    }

    public function testAutoWiringWithDependencies(): void
    {
        // Act
        $result = $this->container->get(ServiceWithDependency::class);

        // Assert
        self::assertInstanceOf(ServiceWithDependency::class, $result);
        self::assertInstanceOf(Dependency::class, $result->dependency);
    }

    public function testAutoWiringWithNestedDependencies(): void
    {
        // Act
        $result = $this->container->get(ServiceWithNestedDependency::class);

        // Assert
        self::assertInstanceOf(ServiceWithNestedDependency::class, $result);
        self::assertInstanceOf(ParentDependency::class, $result->parent);
        self::assertInstanceOf(NestedDependency::class, $result->parent->nested);
    }

    public function testAutoWiringWithDefaultValue(): void
    {
        // Act
        $result = $this->container->get(ServiceWithDefaultValue::class);

        // Assert
        self::assertInstanceOf(ServiceWithDefaultValue::class, $result);
        self::assertEquals('default', $result->param);
    }

    public function testGetNonExistentClassThrowsException(): void
    {
        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Class or interface NonExistentClass does not exist');

        // Act
        /** @var class-string<object> $nonExistentClass */
        $nonExistentClass = 'NonExistentClass';
        $this->container->get($nonExistentClass);
    }

    public function testGetWithUnresolvableBuiltinTypeThrowsException(): void
    {
        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve built-in type for parameter value');

        // Act
        $this->container->get(ServiceWithBuiltinType::class);
    }

    public function testGetWithUnionTypeThrowsException(): void
    {
        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve union or intersection type for parameter param');

        // Act
        $this->container->get(ServiceWithUnionType::class);
    }

    public function testGetWithNoTypeHintThrowsException(): void
    {
        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter param: no type hint');

        // Act
        $this->container->get(ServiceWithNoTypeHint::class);
    }

    public function testCircularDependencyThrowsException(): void
    {
        // Assert
        $this->expectException(ContainerException::class);

        // Act
        $this->container->get(Circular1::class);
    }

    public function testContainerPassedToFactory(): void
    {
        // Arrange
        $containerPassed = null;
        $this->container->set(\stdClass::class, static function (Container $container) use (&$containerPassed) {
            $containerPassed = $container;

            return new \stdClass();
        });

        // Act
        $this->container->get(\stdClass::class);

        // Assert
        self::assertSame($this->container, $containerPassed);
    }

    public function testMultipleBindings(): void
    {
        // Arrange
        $this->container->bind(TestInterface::class, Implementation1::class);
        $first = $this->container->get(TestInterface::class);

        // Act
        $this->container->bind(TestInterface::class, Implementation2::class);
        $second = $this->container->get(TestInterface::class);

        // Assert
        self::assertInstanceOf(Implementation1::class, $first);
        self::assertInstanceOf(Implementation2::class, $second);
    }

    public function testUnboundInterfaceIsNotRegistered(): void
    {
        self::assertFalse($this->container->has(TestInterface::class));
    }

    public function testUnboundInterfaceRequiresExplicitBinding(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('No binding found for interface ' . TestInterface::class);

        $this->container->get(TestInterface::class);
    }

    public function testHasDoesNotAutoloadUnknownIds(): void
    {
        $autoloaded = [];
        $observer = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($observer, prepend: true);

        try {
            $unknownClass = __NAMESPACE__ . '\UnregisteredService';

            self::assertFalse($this->container->has($unknownClass));
            self::assertSame([], $autoloaded);
        } finally {
            spl_autoload_unregister($observer);
        }
    }

    #[DataProvider('registrationStates')]
    public function testFactoryOverridesEarlierBinding(bool $checkRegistration, bool $resolve): void
    {
        $this->container->bind(TestInterface::class, Implementation1::class);
        if ($checkRegistration) {
            self::assertTrue($this->container->has(TestInterface::class));
        }
        if ($resolve) {
            self::assertInstanceOf(Implementation1::class, $this->container->get(TestInterface::class));
        }

        $replacement = new Implementation2();
        $this->container->set(TestInterface::class, static fn(): TestInterface => $replacement);

        self::assertTrue($this->container->has(TestInterface::class));
        self::assertSame($replacement, $this->container->get(TestInterface::class));
        self::assertSame($replacement, $this->container->get(TestInterface::class));
    }

    #[DataProvider('registrationStates')]
    public function testBindingOverridesEarlierFactory(bool $checkRegistration, bool $resolve): void
    {
        $original = new Implementation1();
        $this->container->set(TestInterface::class, static fn(): TestInterface => $original);
        if ($checkRegistration) {
            self::assertTrue($this->container->has(TestInterface::class));
        }
        if ($resolve) {
            self::assertSame($original, $this->container->get(TestInterface::class));
        }

        $this->container->bind(TestInterface::class, Implementation2::class);
        $replacement = $this->container->get(TestInterface::class);

        self::assertTrue($this->container->has(TestInterface::class));
        self::assertInstanceOf(Implementation2::class, $replacement);
        self::assertSame($replacement, $this->container->get(TestInterface::class));
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function registrationStates(): iterable
    {
        yield 'unresolved' => [false, false];
        yield 'checked with has' => [true, false];
        yield 'resolved' => [true, true];
    }
}
