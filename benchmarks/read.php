<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;
use Crab\Tide\HasMany;
use Crab\Tide\Model;
use Crab\Tide\Table;
use Crab\Tide\Tide;

#[Table('parents')]
final class BenchParent extends Model
{
    public int $id;
    public string $name;
    public int $score;
    #[HasMany(BenchChild::class, 'parent_id')]
    public array $children;
}

#[Table('children')]
final class BenchChild extends Model
{
    public int $id;
    public int $parent_id;
    public string $name;
}

final readonly class ParentRecord
{
    public function __construct(public int $id, public string $name, public int $score) {}
}

final readonly class ChildRecord
{
    public function __construct(public int $id, public int $parent_id, public string $name) {}
}

$db = new Database(static fn(): PDO => new PDO('sqlite::memory:'));
Tide::use($db);
$db->execute('create table parents (id integer primary key, name text, score integer)');
$db->execute('create table children (id integer primary key, parent_id integer, name text)');
$db->execute('create index children_parent on children(parent_id)');
$db->transaction(static function (Connection $tx): void {
    for ($id = 1; $id <= 10_000; ++$id) {
        $tx->table('parents')->insert(['id' => $id, 'name' => 'Parent', 'score' => 7]);
        $tx->table('children')->insert(['id' => $id, 'parent_id' => $id, 'name' => 'Child']);
    }
});

printf("PHP %s; SQLite %s; JIT %s; median milliseconds\n", PHP_VERSION, $db->first('select sqlite_version() as version')['version'], ini_get('opcache.jit'));
echo "rows,mode,read_ms,read_and_relation_ms\n";
foreach ([1 => 100, 100 => 30, 10_000 => 7] as $size => $repeats) {
    foreach (['arrays', 'records', 'models'] as $mode) {
        $timings = [];
        foreach ([false, true] as $relations) {
            $samples = [];
            for ($run = -1; $run < $repeats; ++$run) {
                $start = hrtime(true);
                if ($mode === 'models') {
                    $query = BenchParent::query()->where('id', '<=', $size);
                    if ($relations) {
                        $query->with('children');
                    }
                    $parents = $query->get();
                } else {
                    $query = $db->table('parents')->select('id', 'name', 'score')->where('id', '<=', $size);
                    if ($mode === 'records') {
                        $query->into(ParentRecord::class);
                    }
                    $parents = $query->get();
                    if ($relations) {
                        $groups = [];
                        foreach (array_chunk(array_column($parents, 'id'), 1_000) as $keys) {
                            $children = $db->table('children')->select('id', 'parent_id', 'name')->whereIn('parent_id', $keys)->orderBy('id');
                            if ($mode === 'records') {
                                $children->into(ChildRecord::class);
                            }
                            foreach ($children->get() as $child) {
                                $key = is_array($child) ? $child['parent_id'] : $child->parent_id;
                                $groups[$key][] = $child;
                            }
                        }
                    }
                }
                if (count($parents) !== $size) {
                    throw new RuntimeException('The benchmark did not read every parent.');
                }
                if ($run >= 0) {
                    $samples[] = (hrtime(true) - $start) / 1e6;
                }
                unset($parents, $groups);
            }
            sort($samples);
            $timings[] = $samples[intdiv(count($samples), 2)];
        }
        printf("%d,%s,%.4f,%.4f\n", $size, $mode, ...$timings);
    }
}
$db->close();
