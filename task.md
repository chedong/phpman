# Checklist: Modernize phpMan for PHP 8.5 and LLM Agents

- [x] Check local system commands and setup development server <!-- id: 0 -->
- [x] Implement PHP 8.5 compatibility updates in `phpMan.php` <!-- id: 1 -->
  - [x] Add type annotations for function parameters and return values
  - [x] Update backreferences in `preg_replace` (replace `\\1` with `$1`)
  - [x] Replace `show_source` with `highlight_file`
  - [x] Eliminate dynamic properties warning/issues
- [x] Implement Markdown format detection and renderer <!-- id: 2 -->
  - [x] Add detection for `format=markdown` and `Accept: text/markdown`
  - [x] Add `formatManPerlDocToMarkdown` formatter
  - [x] Linkify man page/perldoc references as markdown links
  - [x] Add short-circuit logic to render raw markdown response without HTML templates
- [x] Verify functionality locally <!-- id: 3 -->
  - [x] Start local dev server and test HTML rendering
  - [x] Test Markdown output with curl query parameters and Accept headers
  - [x] Test fallback/error handling
- [x] Create walkthrough.md to document the changes <!-- id: 4 -->
