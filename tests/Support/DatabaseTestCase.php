<?php

declare(strict_types=1);

namespace Crab\Tide\Tests\Support;

use Crab\Database\Database;
use Crab\Database\Mysql\Connection as MysqlConnection;
use Crab\Engine\EventLoop;
use Crab\Tide\Tide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Models.php';

abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;
    protected array $statements = [];
    private array $files = [];
    private array $databases = [];
    private bool $mainFixtures = false;

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
    }

    protected function database(string $driver, ?string $dsn = null): Database
    {
        $file = sys_get_temp_dir() . '/tide-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->files[] = $file;
        $dsn ??= $driver === 'sqlite' ? 'sqlite:' . $file : (string) getenv('CRAB_TEST_MYSQL_DSN');
        $connect = static fn(): \PDO => new \PDO(
            $dsn,
            getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
            getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
        );
        $db = new Database(
            $connect,
            factory: $driver === 'mysql-async'
                ? static fn(): MysqlConnection => new MysqlConnection(
                    $dsn,
                    getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
                    getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
                )
                : null,
        );
        $this->databases[] = $db;
        return $db;
    }

    protected function start(string $driver): void
    {
        $this->db = $this->database($driver);
        $this->mainFixtures = true;
        Tide::use($this->db);
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        $tables = [
            'tide_authors' => "id {$id}, name varchar(100) not null",
            'tide_books' => "id {$id}, title varchar(100) not null, author_id integer null, status varchar(20) not null default 'draft', votes integer not null default 0, meta varchar(4096) not null default '{}', published_at datetime null, secret varchar(100) not null default 'hidden'",
            'tide_chapters' => "id {$id}, book_id integer not null, title varchar(100) not null",
            'tide_notes' => "id {$id}, chapter_id integer not null, body varchar(100) not null",
            'tide_isbns' => "id {$id}, book_id integer not null, code varchar(100) not null",
            'tide_tags' => "id {$id}, name varchar(100) not null",
            'tide_book_tag' => 'book_id integer not null, tag_id integer not null',
            'tide_categories' => "id {$id}, parent_id integer null, name varchar(100) not null",
            'tide_prices' => "id {$id}, amount integer not null",
        ];
        foreach ($tables as $table => $columns) {
            $this->db->execute('drop table if exists ' . $table);
            $this->db->execute('create table ' . $table . ' (' . $columns . ')');
        }
        $this->db->table('tide_authors')->insertMany([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]);
        $this->db
            ->table('tide_books')
            ->insertMany([
                ['id' => 1, 'title' => 'Alpha', 'author_id' => 1, 'votes' => 1],
                ['id' => 2, 'title' => 'Beta', 'author_id' => 1, 'votes' => 2],
                ['id' => 3, 'title' => 'Gamma', 'author_id' => 2, 'votes' => 3],
                ['id' => 4, 'title' => 'Delta', 'author_id' => null, 'votes' => 4],
            ]);
        $this->db
            ->table('tide_chapters')
            ->insertMany([
                ['id' => 1, 'book_id' => 1, 'title' => 'First'],
                ['id' => 2, 'book_id' => 1, 'title' => 'Second'],
                ['id' => 3, 'book_id' => 2, 'title' => 'Only'],
            ]);
        $this->db->table('tide_notes')->insert(['id' => 1, 'chapter_id' => 2, 'body' => 'A note']);
        $this->db
            ->table('tide_isbns')
            ->insertMany([
                ['id' => 1, 'book_id' => 1, 'code' => 'first'],
                ['id' => 2, 'book_id' => 1, 'code' => 'second'],
            ]);
        $this->db->table('tide_tags')->insertMany([['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'SQL']]);
        $this->db
            ->table('tide_book_tag')
            ->insertMany([
                ['book_id' => 1, 'tag_id' => 1],
                ['book_id' => 1, 'tag_id' => 2],
                ['book_id' => 2, 'tag_id' => 1],
            ]);
        $this->db
            ->table('tide_categories')
            ->insertMany([
                ['id' => 1, 'parent_id' => null, 'name' => 'root'],
                ['id' => 2, 'parent_id' => 1, 'name' => 'child'],
                ['id' => 3, 'parent_id' => 2, 'name' => 'leaf'],
            ]);
    }

    protected function tearDown(): void
    {
        if ($this->mainFixtures) {
            foreach ([
                'tide_book_tag',
                'tide_isbns',
                'tide_notes',
                'tide_chapters',
                'tide_books',
                'tide_authors',
                'tide_tags',
                'tide_categories',
                'tide_prices',
                'tide_strings',
            ] as $table) {
                $this->db->execute('drop table if exists ' . $table);
            }
        }
        foreach ($this->databases as $database) {
            $database->close();
        }
        foreach ($this->files as $file) {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                unlink($path);
            }
        }
        EventLoop::current()->run();
    }

    protected function recording(): RecordingConnection
    {
        return new RecordingConnection($this->db);
    }
}
