<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Tide\Model;
use Crab\Tide\Table;
use Crab\Tide\Tide;
use Crab\Tide\TidePlugin;
use PHPUnit\Framework\TestCase;

final class TidePluginTest extends TestCase
{
    protected function setUp(): void
    {
        new \ReflectionProperty(Tide::class, 'database')->setValue(null, null);
    }

    public function testMissingSetupExplainsThePluginAndTestSetup(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('list TidePlugin in app.php, or call Tide::use($database) in a test');
        BeforePluginModel::query();
    }

    public function testThePluginResolvesTheDatabaseLazilyAndOnlyOnce(): void
    {
        $database = $this->createStub(Database::class);
        $container = $this->createMock(Container::class);
        $resolutions = 0;
        $container
            ->expects(self::once())
            ->method('get')
            ->with(Database::class)
            ->willReturnCallback(static function () use ($database, &$resolutions): Database {
                ++$resolutions;
                return $database;
            });
        new TidePlugin()->register($container);
        self::assertSame(0, $resolutions);
        self::assertSame($database, Tide::database());
        self::assertSame($database, Tide::database());
        self::assertSame(1, $resolutions);
    }

    public function testNamedDatabasesComeFromTheDefaultAndUseReplacesTheDefault(): void
    {
        $reports = $this->createStub(Database::class);
        $default = $this->createMock(Database::class);
        $default->expects(self::once())->method('connection')->with('reports')->willReturn($reports);
        Tide::use($default);
        self::assertSame($reports, Tide::database('reports'));
        Tide::use($reports);
        self::assertSame($reports, Tide::database());
    }

    public function testAnInvalidResolverSaysWhatToReturn(): void
    {
        Tide::use(static fn(): string => 'not a database');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('return the application database');
        Tide::database();
    }
}

#[Table('before_plugin')]
final class BeforePluginModel extends Model
{
    public int $id;
}
