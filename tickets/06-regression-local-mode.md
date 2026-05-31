# phpman-regression.sh: support --local flag for pre-deploy testing

**Type:** Feature Request  
**File:** `phpman-regression.sh`

## Description

Currently the regression script only tests against the remote production URL (`https://www.chedong.com/phpMan.php`). This means you can't run a full regression check locally before deploying — you must deploy first, then verify.

## Proposed Solution

Add a `--local` flag that points to a local development server:

```bash
bash phpman-regression.sh --local http://localhost:8080/phpMan.php
```

Implementation:

```bash
# In phpman-regression.sh:
BASE="${PHP_MAN_URL:-https://www.chedong.com/phpMan.php}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --local) BASE="$2"; shift 2 ;;
        --help)  echo "Usage: $0 [--local URL]"; exit 0 ;;
        *)       shift ;;
    esac
done
```

## Benefits

- **Pre-deploy validation**: Run all 10 checks against localhost before `make deploy`
- **CI/CD ready**: Can be used in a GitHub Actions / SourceForge CI pipeline
- **Contributor-friendly**: New contributors can validate their changes end-to-end without deploying to a public server
