# phpMan CLI 工具

CLI 功能已拆分为 `cli/` 下的独立脚本。

## 搜索索引

```bash
php cli/build-index.php              # Rebuild FTS5 search index
php cli/build-index.php --cron       # Rebuild with UTC timestamp
```

## Sitemap

```bash
php cli/build-sitemap.php --output sitemap-phpman.xml.gz
```

## 约定

All CLI scripts resolve `PHPMAN_HOME`, then require `src/bootstrap.php` directly
(no longer load `phpMan.php`). `src/config.php` sets defaults and loads
`~/.phpman/phpman.config.php` for user overrides. The old
`php phpMan.php --build-index` / `--build-index-cron` / `--enhance` flags are removed.

相关 Make 目标：`make reindex` / `make reindex-staging` 调用这些脚本重建索引与
sitemap；完整的 build/deploy 目标列表见根 `CLAUDE.md`。
