# Minor improvements: test runner structured output + $ac global constant

**Type:** Improvement (minor)  
**Files:** `test/run_all.php`, `phpMan.php`

## 1. test/run_all.php: use structured output for pass/fail counting

Currently `run_all.php` uses `preg_match('/(\d+) passed, (\d+) failed/', ...)` to parse human-readable output from each test file. This is fragile — if a test file changes its summary format, the runner breaks silently (counts as 0).

**Suggestion:** Have each test file output a JSON summary line to stderr, e.g.:

```php
fprintf(STDERR, '{"pass":%d,"fail":%d}', $pass, $fail);
```

The runner reads stderr and parses JSON — robust against format changes.

## 2. phpMan.php: `$ac` character class should be a global constant

The overstrike-safe ASCII character class is constructed inside `formatManPerlDoc()`:

```php
$ac = '[ -~' . chr(5) . chr(6) . chr(7) . ']';
```

If any other function needs to match overstrike patterns without splitting multibyte UTF-8, it would need to duplicate this definition.

**Suggestion:** Define it once at the top of the file:

```php
define('RE_ASCII_SAFE', '[ -~\x05\x06\x07]');
```

Then use the constant in `formatManPerlDoc()` and any future function that handles man-page raw output.
