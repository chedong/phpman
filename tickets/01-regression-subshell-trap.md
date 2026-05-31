# phpman-regression.sh: subshell trap in pipe-to-while-read

**Type:** Bug (中等风险)  
**File:** `phpman-regression.sh`  
**Lines:** tests 8 and 9

## Description

Test 8 (Mobile Responsive CSS) and Test 9 (Accessibility: Form Labels) use the pattern:

```bash
curl ... | python3 ... | while read -r line; do
    pass ...   # or fail/warn
done
```

Because `while read` on the right side of a pipe runs in a subshell, the `$PASS`/`$FAIL`/`$WARN` variable increments inside the loop body are lost when the pipeline exits. The summary counting currently works by accident — it uses `grep -c` on `$RESULTS_FILE` which is filesystem-backed and survives the subshell.

## Risk

- If Python outputs **multiple lines**, the `pass/fail` functions fire multiple times for one logical test, polluting the summary counts.
- The subshell behavior is a code smell — future maintainers may add logic that depends on the counters and get confused.
- Current behavior is correct only because each Python script emits exactly one result line.

## Proposed Fix

Replace the pipe-to-while-read with direct variable capture:

```bash
RESULT=$(curl -sf "$URL" | python3 -c "..." 2>/dev/null)
case "$RESULT" in
    OK*)   pass "${RESULT#OK}" ;;
    WARN*) warn "${RESULT#WARN}" ;;
    *)     fail "$RESULT" ;;
esac
```

This eliminates the subshell entirely and ensures one-and-only-one pass/fail/warn per test.
