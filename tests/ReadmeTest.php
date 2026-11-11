<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\App;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadmeTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testEveryPhpExampleRunsFromTheReadmeText(string $driver): void
    {
        $database = $this->database($driver);
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        $database->execute('drop table if exists books');
        $database->execute('drop table if exists authors');
        $database->execute('create table authors (id ' . $id . ', name varchar(100) not null)');
        $database->execute(
            "create table books (id {$id}, title varchar(100) not null, author_id integer null, internal_note varchar(100) not null default 'hidden', created_at datetime null, updated_at datetime null)",
        );
        $database->table('authors')->insert(['id' => 1, 'name' => 'First']);
        $database->table('books')->insert(['id' => 1, 'title' => 'PHP', 'author_id' => 1]);
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        preg_match_all('/```php\n(.*?)\n```/s', $readme, $matches);
        $blocks = [];
        foreach ($matches[1] as $block) {
            preg_match('/\A\/\/ ([a-z-]+\.php)\n/', $block, $name);
            $blocks[$name[1]] = $block;
        }
        self::assertCount(10, $blocks, 'Every PHP block needs a runnable example name.');
        // Separate namespaces allow the exact declarations to run on every driver.
        $namespace = 'TideReadme' . bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir() . '/tide-readme-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory . '/app.php', "<?php\ndeclare(strict_types=1);\n" . $blocks['app.php']);
            $app = require $directory . '/app.php';
            self::assertInstanceOf(App::class, $app);
            unset($blocks['app.php']);
            $source =
                "<?php\ndeclare(strict_types=1);\nnamespace {$namespace};\n"
                . implode("\n", $blocks)
                . "\nreturn [\$books, \$page, \$recent, \$json, \$copy, \$count, \$grace];\n";
            file_put_contents($directory . '/examples.php', $source);
            [$books, $page, $recent, $json, $copy, $count, $grace] = require $directory . '/examples.php';
            self::assertCount(1, $books);
            self::assertCount(1, $page);
            self::assertCount(1, $recent);
            self::assertTrue($copy->stored());
            self::assertSame(2, $count);
            self::assertTrue($grace->stored());
            self::assertSame(5, $database->table('books')->count());
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('First', $decoded['author']['name']);
            self::assertArrayNotHasKey('internal_note', $decoded);
        } finally {
            foreach (['app.php', 'examples.php'] as $file) {
                if (!is_file($directory . '/' . $file)) {
                    continue;
                }
                unlink($directory . '/' . $file);
            }
            rmdir($directory);
            $database->execute('drop table books');
            $database->execute('drop table authors');
        }
    }
}
