<?php
/**
 * JSON structure fingerprint (#255, Task 1) — moved verbatim from
 * test/structure_regression.php so the corpus tooling (cli/corpus.php,
 * test/corpus/check.php) can reuse it.
 */
declare(strict_types=1);

/**
 * Extract section structure fingerprint from JSON data.
 * Format: "L1_NAME(N_lines)>sub1|sub2 ; L1_NAME(N_lines)"
 */
function jsonStructureFingerprint(array $jsonData): string {
    $parts = [];
    foreach ($jsonData['sections'] ?? [] as $sec) {
        $name = $sec['name'] ?? '?';
        $nLines = is_array($sec['content'] ?? null) ? count($sec['content']) : 0;
        $fp = "{$name}({$nLines}lines)";

        // Flag count for OPTIONS section
        $nFlags = count($jsonData['flags'] ?? []);
        if ($nFlags > 0 && $name === 'OPTIONS') {
            $fp .= "[{$nFlags}flags]";
        }

        $subs = [];
        foreach ($sec['subsections'] ?? [] as $sub) {
            $subs[] = $sub['name'] ?? '?';
        }
        if ($subs) {
            $fp .= '>' . implode('|', array_slice($subs, 0, 5))
                 . (count($subs) > 5 ? '|...' : '');
        }
        $parts[] = $fp;
    }
    return implode(' ; ', $parts);
}
