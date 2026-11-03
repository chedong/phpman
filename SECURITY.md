# Security Policy

## Supported Versions

phpMan is a self-hosted documentation server. Only the latest release line gets
security fixes; older lines are expected to be upgraded rather than patched.

| Version | Supported |
|---------|-----------|
| 4.12.x  | ✅        |
| < 4.12  | ❌        |

The running version is shown in the page footer and in `PHPMAN_VERSION`.

## Reporting a Vulnerability

**Please do not open a public issue for an unfixed vulnerability.**

Use GitHub's private reporting, which is enabled on this repository:

<https://github.com/chedong/phpman/security/advisories/new>

That opens a private Security Advisory visible only to the maintainer. If you
cannot use it, email the address on the maintainer's GitHub profile and say you
are reporting a security issue.

Please include:

- what you were able to do, and what you expected instead
- the affected version (the footer shows it) and any configuration that matters
- the smallest request or input that reproduces it
- whether it needs an authenticated MCP key, a local shell, or nothing at all

Expect an acknowledgement within about a week. This is a single-maintainer
project, so a fix may take longer — you will be told which it is rather than left
guessing. Credit is given in the advisory unless you ask otherwise.

## Scope

In scope — bugs in this repository's code:

- remote code execution, command injection, path traversal
- XSS in rendered pages, request smuggling in the MCP endpoint
- authentication or authorization bypass (MCP API key, admin session)
- information disclosure beyond what a page is meant to show

Out of scope — deployment and hosting decisions, which belong to whoever runs
the instance:

- TLS configuration, reverse proxies, WAF/ModSecurity rules
- rate limiting, quotas and DDoS protection
- the contents of `phpman.config.php` (it holds `MCP_API_KEY`; treat it as a
  secret and keep it unreadable by others — `chmod 600`)
- man/perldoc/pydoc content installed on the host

## Security model, briefly

- **The MCP endpoint** requires `MCP_API_KEY` and fails closed: an empty or
  missing key denies every request rather than disabling the check.
- **Request-derived values that reach a shell** go through `escapeshellarg()`;
  the parameter normaliser additionally rejects shell metacharacters and invalid
  UTF-8.
- **Admin actions** live in `edit_lilina.php`: CSRF token, `password_hash`,
  rate limiting, and a `Secure` session cookie.
- **Rendered output** is escaped with `h()`. The Content-Security-Policy in
  `src/web_header.php` currently allows `'unsafe-inline'`, so treat CSP as
  defence-in-depth rather than a hard boundary.
- **Render subprocesses** are bounded by `PHPMAN_CMD_TIMEOUT` (default 15s), so a
  single request cannot hold a worker indefinitely.
