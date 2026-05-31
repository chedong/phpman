# phpman-regression.sh: hardcoded BASE URL

**Type:** Improvement  
**File:** `phpman-regression.sh` line 7

## Description

```bash
BASE="https://www.chedong.com/phpMan.php"
```

The regression test script has a hardcoded production URL. Anyone who forks the project and wants to run the regression tests must first edit this line.

## Proposed Fix

Support environment variable override:

```bash
BASE="${PHP_MAN_URL:-https://www.chedong.com/phpMan.php}"
```

Optionally, also read from `.deploy.mk`:

```bash
if [ -f .deploy.mk ]; then
    DEMO_URL=$(grep '^DEMO_URL' .deploy.mk | sed 's/.*= *//')
    BASE="${PHP_MAN_URL:-${DEMO_URL:-https://www.chedong.com/phpMan.php}}"
else
    BASE="${PHP_MAN_URL:-https://www.chedong.com/phpMan.php}"
fi
```
