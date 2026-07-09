# LLM Gateway Failure Analysis

> Generated: 2026-07-09 | Source: `phpman_error.log` (8,969 lines, 2026-06-09 → 2026-07-09)

## Overview

Analysis of LLM API gateway failures for `batch-enhance.php` over the past
2 weeks (June 26 – July 9, 2026). All requests go to `taotoken.net` API.

## Error Type Breakdown

| Error Type | Count | % | Cause |
|---|---|---|---|
| HTTP 0 (network failure) | 5,375 | 68.5% | DNS/connection/tcp failure |
| HTTP 403 `team_model_not_allowed` | 1,663 | 21.2% | fallback-2 model (longCat-flash-chat) not on allowlist |
| timeout 120s (primary) | 2,472 | — | deepseek-v4-pro not responding |
| timeout 60s (fallback-1) | 2,172 | — | deepseek-v4-flash timeout |
| HTTP 400 `model_not_found` | 343 | 4.4% | fallback-3 model (glm5) does not exist |

**Total LLM errors:** 7,949 (all LLM `[primary]` / `[fallback-1]` / `[fallback-2]` / `[fallback-3]` entries)

## Failures by Endpoint

| Endpoint | Errors | % | Role |
|---|---|---|---|
| `[primary]` deepseek-v4-pro | 2,809 | 35.3% | Primary model, 120s timeout |
| `[fallback-1]` deepseek-v4-flash | 2,785 | 35.0% | First fallback, 60s timeout |
| `[fallback-2]` longCat-flash-chat | 2,290 | 28.8% | Second fallback, 60s timeout → **403** |
| `[fallback-3]` glm5 | 65 | 0.8% | Third fallback → **400 model not found** |

**Key finding:** Primary + fallback-1 account for 70.4% of errors — both timeout.
fallback-2 is completely broken (403), fallback-3 is misconfigured (400).

## Daily Timeout Trend (past 2 weeks)

```
Date           Timeouts   Trend
────           ────────   ─────
Jun 26-28        3-7      Baseline (low volume)
Jun 29           26       █
Jun 30           30       █
Jul 01          458       ██████████████
Jul 02           87       ███
Jul 03          262       ████████
Jul 04          218       ██████
Jul 05          129       ████
Jul 06           51       ██
Jul 07          745       ██████████████████████
Jul 08        2,448       ██████████████████████████████████████████████████
Jul 09 (partial) 596      ██████████████████
```

**Escalation:** Timeout errors spiked dramatically starting July 7.
July 8 was the worst day (2,448 timeout errors in 24 hours).

## Daily All LLM Errors Trend

```
Date          Errors
────          ──────
Jun 29           15
Jun 30           65
Jul 01          513
Jul 02          107
Jul 03          435
Jul 04          371
Jul 05          225
Jul 06           90
Jul 07        1,410
Jul 08        3,802   ← Worst day
Jul 09 (so far) 916
```

## Root Cause Analysis

### 1. Primary timeout (120s)
`deepseek-v4-pro` at `taotoken.net` times out after 120 seconds with 0 bytes
received. This affects ~35% of all errors. The model likely cannot process
large requests (128KB input + 1,024,000 max_tokens) within 120 seconds.

### 2. Fallback-1 timeout (60s)
`deepseek-v4-flash` times out after 60 seconds. Same root cause as primary,
but with a shorter timeout. Together with primary, 70% of errors are timeouts.

### 3. Fallback-2 broken (403)
`longCat-flash-chat` returns `team_model_not_allowed`. Model needs to be
added to the taotoken.net team allowlist. This is the single largest
fixable issue — fixing it would recover ~29% of failed requests.

### 4. Fallback-3 broken (400)
`glm5` returns `model_not_found`. The model name is incorrect or the model
has been removed. Needs configuration update.

## Recommendations

1. **[Critical]** Add `longCat-flash-chat` to taotoken.net team allowlist
   → recovers ~29% of failed requests
2. **[Critical]** Fix or remove fallback-3 model name (`glm5`)
3. **[High]** Reduce `LLM_MAX_TOKENS` from 1,024,000 to 32,768
   → reduces primary timeout rate
4. **[Medium]** Increase primary timeout from 120s to 180s for large pages
5. **[Low]** Consider reducing `PHPMAN_ENHANCE_MAX_CHARS` from 128,000
   to reduce prompt size

## Impact on Enhance Progress

Despite 3,802 errors on July 8 alone, the system still produced ~650 emoji
enhancements. The fallback chain partially works: even when primary fails,
fallback-1 sometimes succeeds (evidenced by "fallback succeeded" log entries).
However, with fallback-2 and fallback-3 both broken, each failure burns
~180 seconds (120s + 60s) before giving up.
