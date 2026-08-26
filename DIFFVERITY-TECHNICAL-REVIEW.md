# DiffVerity Technical Review

## Architecture

DiffVerity 1.0.0 uses namespaced PHP 7.4-compatible classes with a small internal autoloader. Business logic is independent of admin views so a future WP-CLI adapter could reuse the scanner, source providers, classifier, repositories, and diff engine.

Primary layers:

- `Scanner`: component registry, local manifest builder, classifier, and resumable scan manager.
- `Source`: core checksums, plugin checksums/package fallback, theme packages, and Local Baselines behind a common provider contract.
- `Filesystem`: canonical path containment, bounded file reading, symlink refusal, and owned temporary-directory lifecycle.
- `HTTP`: HTTPS-only, host-allowlisted WordPress.org client with no insecure fallback and no unrelated redirects.
- `Storage`: bounded custom tables for scans, file results, baselines, and baseline hashes.
- `Diff`: BOM/line-ending normalization and escaped WordPress-native text differences.
- `Admin`: capability/nonce-protected controllers, responsive views, lightweight vanilla JavaScript, and local CSS only.
- `Export`: JSON, CSV, and TXT evidence without full source or absolute paths.

No frontend hook enqueues an asset.

## Source trust model

The trust label is stored on every result and is intentionally separate from the file status.

1. **Officially Verified**: authoritative exact-version checksums (core and eligible plugins).
2. **Official Source Available**: exact-version package manifest (theme or plugin fallback).
3. **Local Baseline**: administrator-created SHA-256 snapshot; no authenticity claim.
4. **No Trusted Source**: no independent source exists; not a security warning.
5. **Not Verifiable**: verification could not complete because of a source/network/response failure.

Network/source failures generate `Source Unavailable`; they are never converted into `Modified`.

## Checksum mechanisms

Core uses native `get_core_checksums()` with the installed version and locale. `wp-content` payload entries and `wp-config-sample.php` are excluded from the core comparison. Unexpected discovery is conservative: recursive local discovery is limited to `wp-admin` and `wp-includes`, plus official root manifest files.

Plugins use `https://downloads.wordpress.org/plugin-checksums/{slug}/{version}.json`. SHA-256 is preferred when returned; MD5 remains supported because it is part of the authoritative format. A checksum 404 may fall back to the exact package at `downloads.wordpress.org/plugins/{slug}.{version}.zip`, producing **Official Source Available** rather than silently claiming checksum verification.

Themes use `downloads.wordpress.org/theme/{slug}.{version}.zip`. The exact version is encoded from server-side component metadata, not browser input.

Local Baselines always use SHA-256 and may store a normalized SHA-256 for small UTF-8 text. They store no complete source copies.

## Baseline design

Baseline creation requires an authenticated `manage_options`/network-capable administrator, AJAX nonce, deliberate confirmation, and a server-resolved component identifier. Activation never creates a baseline. Creating a new one marks earlier baselines non-current and prunes history to the configured 1–10 bound.

The recorded component version is compared with the current installed version. A version transition produces review-oriented baseline results and never automatically regenerates trust.

## Filesystem safety

All browser operations use result IDs or `type:slug` identifiers. `Component_Registry` re-resolves these against `ABSPATH`, `WP_PLUGIN_DIR`, and the active theme root. Raw browser filesystem paths are never accepted.

`Path_Guard` normalizes separators, rejects null bytes and `..` segments, resolves existing files with `realpath()`, checks path-segment boundaries, and refuses symbolic links. Recursive iterators do not follow links. Missing trusted files are classified from manifest keys without attempting an unsafe read.

`hash_file()` performs streaming hashes. Full reads occur only for an administrator-requested text diff or bounded normalization. UTF-8 and NUL-byte checks prevent arbitrary binary rendering.

## Temporary package handling

Temporary directories use a unique `diffverity-{UUID}` name plus an ownership marker inside `get_temp_dir()`. Cleanup accepts only a real directory inside the canonical temp root whose basename has the DiffVerity prefix and whose ownership marker exists. Recursion never follows symlinks.

Packages stream to an owned file with a configured byte limit. Extraction uses WordPress `unzip_file()` and preflights `ZipArchive` entries when available to reject traversal, absolute paths, drive-prefixed paths, and invalid names. Extracted PHP is never included, required, evaluated, activated, or installed.

ZIP and extraction trees are removed on success, failure, component completion, explicit cancellation, deactivation, and stale cleanup. A 24-hour stale cleanup is gated to run at most twice daily.

## Remote restrictions and privacy

Allowed hosts are exactly `api.wordpress.org`, `downloads.wordpress.org`, and `wordpress.org`; the scheme must be HTTPS, and user credentials/fragments are rejected. HTTP uses `wp_safe_remote_get()`, certificate verification, no redirects, bounded response/package sizes, and conservative timeouts. A redirect is therefore not silently followed to an unrelated host.

External communication is limited to authoritative comparison services and happens only during administrator-triggered scans/diffs. No source code, database data, customer information, admin username, site content, telemetry, or analytics is sent to a vendor service. The request disclosure is in `readme.txt`.

## Database and storage

Dedicated tables are justified because a site can produce tens of thousands of file results and baseline hashes. Large data is not serialized into autoloaded options.

| Table | Purpose | Important indexes/bounds |
|---|---|---|
| `diffverity_scans` | Scan lifecycle and aggregate counts | status, started time; 1–100 retained |
| `diffverity_results` | Per-file evidence | scan ID, component, status |
| `diffverity_baselines` | Component/version snapshots | component/current, creation time; 1–10 per component |
| `diffverity_baseline_files` | Relative paths and SHA-256 metadata | unique baseline/path key |

Resumable in-progress state uses a per-scan, non-autoloaded option. It is removed on completion or cancellation. One transient lock prevents conflicting full scans and expires after 30 minutes.

Batch result rows are inserted in one prepared multi-value query. Scan and baseline pruning also removes linked owned rows. Uninstall drops only owned tables/options and only when the administrator enabled deletion beforehand.

## Performance

Each file is hashed once while constructing a component’s local manifest. One component-level checksum request replaces per-file HTTP calls. Official responses use a six-hour transient keyed by exact slug/version. Package materialization is lazy for plugin/core text normalization and reused within the component state.

Defaults cap batches at 200 files, interactive text at 1 MB, packages at 50 MB, HTTP at 20 seconds, scan history at 20, and baseline history at 3. Configured ranges prevent unbounded values.

The isolated PHP 8.4 manifest benchmark measured 5,000 small files in 0.299 seconds at 6 MB peak memory and 20,000 files in 1.003 seconds at 20 MB peak memory. These are engine-only synthetic figures, not production server guarantees; storage, disk, CPU, network, and file size materially affect real scans.

## Security controls

- Every privileged controller checks capability.
- Every state-changing request checks a nonce in addition to capability.
- Inputs are read field-by-field, unslashed, validated against allowlists/ranges, and sanitized.
- Output is escaped at render time; source diff HTML is restricted to a small `wp_kses()` table/diff allowlist.
- Exports omit source and absolute paths.
- SQL dynamic values use `$wpdb->prepare()` or structured `$wpdb` CRUD calls; table names come from an internal allowlist.
- No insecure SSL option, arbitrary source URL, remote executable code, repair, quarantine, chmod, or production-file deletion exists.

## Multisite

Core/plugin/theme files are treated as shared physical files. Activation creates storage only in the active context and does not loop through sites. Network admin access uses `manage_network_options`; ordinary site admin uses `manage_options`. The tested network-activation path does not fatal. Version 1.0 does not aggregate per-site scan histories into a separate global network table.

## Known limitations

- Local Baselines cannot display original source lines because they deliberately retain hashes rather than copies.
- Package comparison depends on the exact version remaining available on WordPress.org.
- A symbolic-link-based deployment is reported as Skipped rather than followed.
- In-progress state can be sizable for a very large single component, although it remains non-autoloaded and bounded per component.
- Only core, plugins, and themes are in scope; uploads, database, arbitrary directories, and server configuration are not scanned.
- DiffVerity reports file evidence, not malware intent, vulnerability status, or overall site security.
