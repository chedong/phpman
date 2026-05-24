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

![phpMan: perldoc page with TOC sidebar](https://a.fsdn.com/con/app/proj/phpunixman/screenshots/QQ20121226-2.png/600/450/1)

## Features

- **Man Pages** — Browse any Unix/Linux manual page by section
- **Perldoc** — Read Perl module documentation in-browser
- **Info Pages** — View GNU info documentation
- **Apropos Search** — Full-text search across man page summaries
- **TOC Sidebar** — Two-level floating table of contents for navigation
- **Markdown Output** — Append `/markdown` to any URL for machine-readable format
- **SEO Optimized** — Canonical URLs, meta description, robots directives
- **Clean URLs** — PATH_INFO routing: `/man/ls/1`

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

Upload the compressed archive to SourceForge File Release System:

```bash
gzip -k -f phpMan.php
scp phpMan.php.gz chedong@frs.sourceforge.net:/home/frs/project/phpunixman/phpMan.php.gz
```

Or upload manually via: <https://sourceforge.net/projects/phpunixman/files/>

## License

GNU General Public License v2.0 — see [copyright page](https://www.chedong.com/phpMan.php/copyright).

## Author

Che Dong — <https://www.chedong.com/>
