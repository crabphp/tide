# Reading measurements

Measured before implementing `save()`, on PHP 8.4.22, SQLite 3.51.2, JIT disabled. The standalone script creates an in-memory database with 10,000 parents and one child per parent. It compares the same three scalar columns, with warm metadata and prepared statements. Times are medians in milliseconds: 100 repetitions for one row, 30 for 100 rows, and seven for 10,000 rows, after one warm-up.

```sh
php -d opcache.jit=0 benchmarks/read.php
```

| Rows | Arrays | Records | Models | Arrays + relation | Records + relation | Models + relation |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 0.0070 | 0.0093 | 0.0127 | 0.0180 | 0.0223 | 0.0357 |
| 100 | 0.0347 | 0.0968 | 0.1204 | 0.1428 | 0.2649 | 0.3762 |
| 10,000 | 2.6008 | 8.8201 | 11.1866 | 11.9388 | 24.3219 | 41.6308 |

Decision: proceed with writing. Model reads cost about 1.27 times record reads at 10,000 rows, including the original-value snapshot. Relation loading also validates mappings, matches and assigns relations, and shares objects; the array and record baselines query and group children without assigning them to parent objects. These are local SQLite measurements, not a throughput claim or a MySQL benchmark. The script remains reproducible; later results may vary with host load.
