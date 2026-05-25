# phpMan — Unix Man Page / Perldoc / Info Page Web Interface

A single-file PHP web interface for Unix `man`, `perldoc`, `info`, and `apropos` commands.
Read lengthy manual pages in your browser — with syntax highlighting, section navigation, and a floating TOC sidebar.

## Project Home

- **SourceForge:** <https://sourceforge.net/projects/phpunixman>
- **Live Demo:** <https://www.chedong.com/phpMan.php>
- **Static Site:** <https://phpunixman.sourceforge.io/>

> ⚠️ **SourceForge no longer supports PHP (since 2025-05-20).**
> The dynamic demo runs on `chedong.com`; the `sourceforge.io` site is a static project introduction page.

## Screenshot

![phpMan: perldoc page with TOC sidebar](https://a.fsdn.com/con/app/proj/phpunixman/screenshots/%E4%BC%81%E4%B8%9A%E5%BE%AE%E4%BF%A120260525-161915%402x-8c442be2.png/750/400)

## Features

- **Man Pages** — Browse any Unix/Linux manual page by section
- **Perldoc** — Read Perl module documentation in-browser
- **Info Pages** — View GNU info documentation
- **Apropos Search** — Full-text search across man page summaries
- **TOC Sidebar** — Two-level floating table of contents for navigation
- **Markdown Output** — Append `/markdown` to any URL for machine-readable format
- **SEO Optimized** — Canonical URLs, meta description, robots directives
- **Clean URLs** — PATH_INFO routing: `/man/ls/1`

## Comparison: man / Info / Perldoc Modes

phpMan supports three Unix documentation retrieval methods, each corresponding to different system commands, data sources, and documentation format specifications.

### 1. man Mode

| Item | Description |
|------|-------------|
| **System Command** | `man -Tascii <argument>` |
| **Data Source** | `/usr/share/man/`, `/usr/local/share/man/` — files with `.1.gz`, `.3pm.gz` etc. |
| **Source Format** | **troff / groff** (AT&T typesetting language), original content contains overstrike sequences (e.g., `W^HWA^HAR^HRN^H...`) |
| **Standard** | **man-pages(7)** — 9 sections: 1=user commands, 2=system calls, 3=C library functions, 4=device files, 5=file formats, 6=games, 7=miscellaneous, 8=system administration, 9=kernel routines |
| **Internal Structure** | Flat document per page, fixed sections include NAME, SYNOPSIS, DESCRIPTION, OPTIONS, EXAMPLES, SEE ALSO, etc. |
| **Subsections** | Supports second-level subsections (`.SS` macro → bold/underline), fully displayed in TOC |

### 2. info Mode

| Item | Description |
|------|-------------|
| **System Command** | `info <argument>` |
| **Data Source** | `/usr/share/info/` — files with `.info.gz`, `.info` |
| **Source Format** | **Texinfo** (GNU documentation format), original content includes typesetting markers (`* Menu:`, section numbers `4.1`, cross-references `(node)`) |
| **Standard** | **Texinfo** → can generate PDF, HTML, and info. Node is the basic unit, with hypertext links via `(node)` forming a documentation tree |
| **Internal Structure** | Tree-like node structure, can contain submenu nodes, supports jump navigation |
| **Subsections** | Plain text output from `info` has only section numbers (`3.1`, `3.2`) and indentation, no identifiable explicit heading macros, so TOC shows **only first level** |

### 3. perldoc Mode

| Item | Description |
|------|-------------|
| **System Command** | `perldoc <module>` → `perldoc -f <function>` → `perldoc -q <regex>` (three-level fallback) |
| **Data Source** | `.pod` files in Perl installation paths |
| **Source Format** | **POD** (Plain Old Documentation), Perl documentation format, uses `=head1`, `=head2`, `=over`, `=item` markers |
| **Standard** | **perlpod(1)** — `=head1` for major sections, `=head2` for subsections |
| **Internal Structure** | Flat document with clear `=head1` → `=head2` hierarchy |
| **Subsections** | Supports second-level subsections (`=head2`), fully displayed in TOC |

### 4. Cross-Comparison

| Dimension | man | info | perldoc |
|-----------|-----|------|---------|
| Ecosystem | BSD / Unix general | GNU project specific | Perl language specific |
| Source Format | troff / groff | Texinfo | POD |
| Overstrike Output | ✅ Yes | ❌ No | ❌ No (but has ANSI escapes) |
| Second-level Headings | `.SS` → bold/underline | Section number + indent | `=head2` → Title Case |
| TOC Depth | ✅ Full two levels | ❌ First level only | ✅ Full two levels |
| Linking Capability | Weak (cross-reference `name(sec)`) | Strong (node tree `(node)` navigation) | Weak (module reference `Module::Name`) |
| Typical Content | Command references, syscalls, config formats | GNU project complete manuals (tutorials, concepts) | Perl module API references |

> ℹ️ **About info Subsections:** info mode currently cannot generate a second-level TOC because `info` plain text output only has section numbers (e.g., `3.1 Simple options`), lacking explicit heading markers like man's `.SS` or perldoc's `=head2`. Support can be added by extending the heading recognition logic.

## Check Out Source Code

### HTTPS (read-only)

```bash
git clone https://git.code.sf.net/p/phpunixman/code phpman
```

### SSH (developers)

```bash
git clone ssh://chedong@git.code.sf.net/p/phpunixman/code.git phpman
```

## Quick Start

Deploy phpMan on any PHP 8.x server with a single file:

```bash
# Clone the repository
git clone https://git.code.sf.net/p/phpunixman/code phpman

# Copy to your web server's document root
cp phpman/phpMan.php /var/www/html/

# Access in browser
# https://your-server/phpMan.php
```

For Apache 2.x, ensure `AcceptPathInfo On` is configured to enable clean URL routing.

## Publish Updates

### 1. Commit and Push to SourceForge Git

```bash
git add phpMan.php
git commit -m "description of changes"
git push origin master
```

### 2. Update Live Demo (chedong.com)

```bash
scp phpMan.php chedong.com:~/chedong.com/phpMan.php
```

> ⚠️ Do **not** overwrite `index.php` — only update `phpMan.php`.

### 3. Update Static Site (SourceForge project web)

```bash
scp index.html chedong@web.sourceforge.net:/home/project-web/phpunixman/htdocs/index.html
```

The `index.html` is a static project introduction page with screenshot and a demo link pointing to `chedong.com/phpMan.php`.

### 4. Upload Release

Upload the compressed archive and README to SourceForge File Release System:

```bash
gzip -k -f phpMan.php
scp phpMan.php.gz README.md chedong@frs.sourceforge.net:/home/frs/project/phpunixman/
```

README.md will be rendered below the file listing on the Files page.
Or upload manually via: <https://sourceforge.net/projects/phpunixman/files/>

## License

GNU General Public License v2.0 — see [copyright page](https://www.chedong.com/phpMan.php/copyright).

## Author

Che Dong — <https://www.chedong.com/>
