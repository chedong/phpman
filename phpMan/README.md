# phpMan - Unix Manual Pages in PHP

phpMan is a PHP-based Unix manual page viewer. It parses man page output and renders it as HTML.

## Project Home

- <https://sourceforge.net/projects/phpunixman>

## Check Out Source Code

### HTTPS (read-only)

```bash
git clone https://git.code.sf.net/p/phpunixman/code phpunixman
cd phpunixman
```

### SSH (developers)

```bash
git clone ssh://chedong@git.code.sf.net/p/phpunixman/code.git phpunixman
cd phpunixman
```

## Publish Updates

### 1. Commit and Push to SourceForge Git

```bash
git add phpMan.php
git commit -m "description of changes"
git push sf master
```

> **Note:** the SourceForge remote is named `sf`, and the URL must end with `.git`:
> `ssh://chedong@git.code.sf.net/p/phpunixman/code.git`

### 2. Update Homepage (SourceForge web)

```bash
scp phpMan.php chedong@web.sourceforge.net:/home/groups/p/ph/phpunixman/htdocs/index.php
```

### 3. Update Homepage (chedong.com)

```bash
scp phpMan.php chedong.com:/var/www/html/index.php
```

### 4. Upload Release

Upload the compressed archive via SourceForge File Manager:
<https://sourceforge.net/projects/phpunixman/files/>`
