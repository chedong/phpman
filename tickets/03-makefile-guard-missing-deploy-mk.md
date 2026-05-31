# Makefile: missing friendly error when .deploy.mk is absent

**Type:** Improvement  
**File:** `Makefile`

## Description

When `.deploy.mk` doesn't exist, `make deploy` attempts to scp to `your-user@example.com` using the Makefile's default fallback values. The user sees a cryptic SSH connection error (host not found or permission denied) instead of a clear message telling them to configure `.deploy.mk`.

## Steps to Reproduce

1. Delete or rename `.deploy.mk`
2. Run `make deploy`
3. See: `ssh: Could not resolve hostname example.com` or similar

## Proposed Fix

Add a guard at the top of Makefile (after the `-include .deploy.mk` line):

```makefile
ifeq ($(TEST_HOST),example.com)
$(error Please copy .deploy.mk.example to .deploy.mk and configure your server settings)
endif
```

This produces a clear, immediate error:

```
Makefile:9: *** Please copy .deploy.mk.example to .deploy.mk and configure your server settings.  Stop.
```

The `-include` prefix ensures make doesn't error if the file is missing — the guard handles that case explicitly.
