# Extract mobile CSS from showHeader() to a top-level variable

**Type:** Improvement (technical debt)  
**File:** `phpMan.php` — `showHeader()` function

## Description

The `showHeader()` function is ~80 lines long, and the inline CSS block (especially the mobile `@media` section) accounts for ~20 of those lines. The mobile CSS block has grown from 1 rule to 14 rules over recent commits and will likely continue to grow.

Extracting the CSS into a named variable at the top of the file would:
- Make `showHeader()` more readable (focus on HTML generation, not CSS details)
- Make CSS modifications safer (can't accidentally break PHP string escaping)
- Follow the single-file design principle (no external CSS file)

## Proposed Fix

```php
// Top of phpMan.php, near other config constants
$MOBILE_CSS = <<<'CSS'
@media (max-width:1024px){
    body.ext-nav #toc-sidebar{display:none !important;}
    #toc-sidebar{display:none !important;}
    #content-wrap{margin-right:0;max-width:100%;padding:0 8px;}
    body{font-size:12px;}
    #man-content pre{white-space:pre-wrap;word-wrap:break-word;font-size:12px;line-height:1.4;}
    input[type='text']{width:100%;font-size:16px;padding:8px;box-sizing:border-box;}
    input[type='submit']{font-size:16px;padding:10px 20px;min-height:44px;}
    input[type='radio']{transform:scale(1.3);margin-right:4px;}
    form p{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
    form a{padding:6px 8px;display:inline-block;}
    a{padding:4px 2px;}
    p{font-size:12px;line-height:1.6;}
}
CSS;

// In showHeader(), replace the @media block with:
echo $MOBILE_CSS;
```

Using NOWDOC syntax (`<<<'CSS'`) avoids having to escape `$` in CSS property values.
