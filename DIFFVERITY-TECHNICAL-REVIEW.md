# DiffVerity Technical Review

Release: 1.2.1

## Scope

DiffVerity is a read-only WordPress plugin for evidence-based comparisons of WordPress core, plugins, and themes. It does not repair, delete, quarantine, overwrite, or change permissions on installed files.

## Architecture

The plugin uses a PHP 7.4-compatible namespaced autoloader and separates scanner orchestration, filesystem reads and path containment, WordPress.org source clients, trusted-source providers, hash/size/text-diff generation, persistent scan/result/history/baseline/export services, and capability-protected admin handlers.

## Security controls

Privileged screens require WordPress capabilities. State-changing requests use nonces. Inputs are unslashed, sanitized, range-checked, and allowlisted; outputs are escaped. Diff HTML is restricted.

Filesystem containment rejects null bytes, traversal, and symlinks outside the intended root. Temporary package files are owned and cleaned up. Database access uses prepared or structured queries. The plugin does not accept arbitrary source URLs, disable certificate verification, execute downloaded code, repair files, or delete production data.

## Trust model

The plugin distinguishes exact-version authoritative checksums, exact-version official packages, official core bundles, administrator-created SHA-256 baselines, unavailable sources, and source failures. A source failure is not reported as a file mismatch.

## Performance

Default limits include 200 files per batch, a 1 MB text-diff limit, 20-second HTTP timeouts, 50 MB package limits, 20 retained scans, and 3 retained baselines. Settings remain bounded. Scan state is resumable and non-autoloaded, with a scan lock to prevent concurrent full scans.

## Compatibility

The plugin targets WordPress 6.2 or newer and PHP 7.4 or newer, uses standard WordPress hooks and APIs, and loads no frontend assets.

## Validation

The 1.2.1 release was checked with PHP syntax linting, Node.js syntax checking, WordPress.org readme validation, metadata consistency checks, source security review, and asset dimension checks.
