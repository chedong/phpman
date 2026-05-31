# phpMan.php: consolidate two scattered PHPMAN_TEST_MODE gates

**Type:** Improvement  
**File:** `phpMan.php`

## Description

Currently there are two separate `if (!defined('PHPMAN_TEST_MODE'))` blocks:

1. **Lines ~84-92**: wraps `$VALIDATOR` initialization (server-dependent)
2. **Lines ~337+**: wraps the entire main execution flow (routing, output)

Having two scattered gates increases the risk that future code added between them will miss the test-mode guard.

## Proposed Fix

Option A — Move the validator init into the larger gate block (simplest):

```php
if (!defined('PHPMAN_TEST_MODE')) {
    // server-dependent vars
    $scheme = ...;
    $VALIDATOR = ...;
    
    // main execution
    // ... routing, output, footer ...
}
```

Option B — Use an early return (cleaner separation):

```php
// After all function definitions, before main execution:
if (defined('PHPMAN_TEST_MODE')) {
    return; // Test mode: define functions only, skip execution
}

// All server-dependent init and main logic follows...
```

Option B is preferred — it makes the intent explicit and eliminates the risk entirely.
