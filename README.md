# DiffVerity – File Integrity & Source Diff

Current release: **1.2.1**

DiffVerity is a read-only WordPress integrity investigation plugin. It compares WordPress core, plugins, and themes with authoritative exact-version sources or administrator-created local baselines, then presents evidence about what changed and why the source can or cannot be trusted.

## Highlights

- Exact-version WordPress core, plugin, and theme comparison.
- Hash-only local baselines for private, premium, and custom components.
- Clear trust labels and statuses for modified, missing, unexpected, soft-difference, skipped, unavailable, and not-verifiable results.
- Escaped text diffs with hash and size evidence for binaries or large files.
- Resumable, resource-bounded scans with cancellation and scan locking.
- Paginated results and bounded scan history.
- JSON, CSV, and text exports without full source code or absolute server paths.
- No frontend assets, telemetry, tracking, firewall, automated repair, or malware claims.

## Installation

Install the stable release from [WordPress.org](https://wordpress.org/plugins/diffverity-file-integrity-source-diff/) or download the [1.2.1 ZIP](https://downloads.wordpress.org/plugin/diffverity-file-integrity-source-diff.1.2.1.zip).

For development, copy the `diffverity` directory into the WordPress plugins directory and activate it from the WordPress admin.

## Requirements

- WordPress 6.2 or newer.
- PHP 7.4 or newer.
- Administrator access to run scans and manage settings.

## Repository layout

- `diffverity/` — installable plugin source.
- `assets/` — WordPress.org banner and icon media.
- `DIFFVERITY-USER-GUIDE.md` — administrator usage guide.
- `DIFFVERITY-TECHNICAL-REVIEW.md` — architecture, security, and performance notes.

## Privacy and safety

DiffVerity does not upload local source code or site content. Administrator-triggered scans contact only the documented WordPress.org services needed for exact-version comparison. The plugin never modifies installed files.

See the plugin readme for the full external-service disclosure and limitations.

## Validation

The 1.2.1 release was checked with PHP syntax linting, Node.js syntax checking, WordPress.org readme validation, metadata consistency checks, source security review, and asset dimension checks.
