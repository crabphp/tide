<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Tide\BelongsTo;
use Crab\Tide\BelongsToMany;
use Crab\Tide\HasMany;
use Crab\Tide\HasOne;
use Crab\Tide\Model;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

final class NamedDatabaseTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testRelationsUseTheOtherDatabasesCurrentConnectionAndPivot(string $driver): void
    {
        $main = $this->database($driver);
        $schema = null;
        $dsn = null;
        if ($driver !== 'sqlite') {
            $schema = 'crab_tide_' . bin2hex(random_bytes(6));
            $main->execute('create database ' . $schema);
            $dsn = preg_replace('/dbname=[^;]*/', 'dbname=' . $schema, (string) getenv('CRAB_TEST_MYSQL_DSN'));
        }
        try {
            $reports = $this->database($driver, $dsn);
            $main->execute('drop table if exists tide_sites');
            $main->execute('create table tide_sites (id integer primary key, report_id integer null)');
            $reports->execute(
                'create table tide_reports (id integer primary key, site_id integer, label varchar(100))',
            );
            $reports->execute('create table tide_site_report (site_id integer, report_id integer)');
            $main->table('tide_sites')->insert(['id' => 1, 'report_id' => 10]);
            $reports->table('tide_reports')->insert(['id' => 10, 'site_id' => 1, 'label' => 'Remote']);
            $reports->table('tide_site_report')->insert(['site_id' => 1, 'report_id' => 10]);
            $default = $this->createStub(Database::class);
            $default->method('current')->willReturnCallback($main->current(...));
            $default->method('connection')->willReturnCallback(static fn(string $name): Database => $name === 'reports'
                ? $reports
                : throw new \LogicException('Unexpected name.'));
            Tide::use($default);
            $site = LocalSite::query()->with('report', 'reports', 'firstReport', 'linkedReports')->find(1);
            self::assertSame('Remote', $site->report->label);
            self::assertSame('Remote', $site->reports[0]->label);
            self::assertSame('Remote', $site->firstReport->label);
            self::assertSame('Remote', $site->linkedReports[0]->label);
            self::assertSame('Remote', $site->related('linkedReports')->first()->label);
            $seen = [];
            try {
                $reports->transaction(static function (Connection $tx) use (&$seen): never {
                    $report = new RemoteReport();
                    $report->id = 11;
                    $report->site_id = 1;
                    $report->label = 'Uncommitted';
                    $report->save();
                    $seen = [
                        RemoteReport::query()->connection === $tx,
                        count(LocalSite::query()->with('reports')->find(1)->reports),
                    ];
                    throw new \RuntimeException('rollback');
                });
            } catch (\RuntimeException) {
                // The relation load must have seen the named database's loan.
            }
            self::assertSame([true, 2], $seen);
            self::assertNull(RemoteReport::find(11));
            foreach (['whereHas', 'whereDoesntHave'] as $method) {
                try {
                    LocalSite::query()->{$method}('report');
                } catch (\LogicException $error) {
                    $message = $error->getMessage();
                }
                self::assertStringContainsString('belongs to another database', $message);
            }
            $reports->close();
        } finally {
            $main->execute('drop table if exists tide_sites');
            if ($schema !== null) {
                $main->execute('drop database ' . $schema);
            }
        }
    }
}

#[Table('tide_sites')]
final class LocalSite extends Model
{
    public int $id;
    public ?int $report_id;
    #[BelongsTo('report_id')]
    public ?RemoteReport $report;
    #[HasMany(RemoteReport::class, 'site_id')]
    public array $reports;
    #[HasOne('site_id')]
    public ?RemoteReport $firstReport;
    #[BelongsToMany(RemoteReport::class, 'tide_site_report', 'site_id', 'report_id')]
    public array $linkedReports;
}

#[Table('tide_reports', database: 'reports')]
final class RemoteReport extends Model
{
    public int $id;
    public int $site_id;
    public string $label;
}
