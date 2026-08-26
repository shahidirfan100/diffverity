# DiffVerity User Guide

DiffVerity – File Integrity & Source Diff compares installed WordPress core, plugin, and theme files with an explicit source of evidence. It is read-only: version 1.0.0 never repairs, deletes, quarantines, overwrites, or changes permissions on installed files.

## Installation

1. In WordPress admin, open **Plugins → Add New → Upload Plugin**.
2. Select `diffverity-1.0.0.zip` and choose **Install Now**.
3. Activate **DiffVerity – File Integrity & Source Diff**.
4. Open **DiffVerity → Overview**.

Activation only creates DiffVerity’s four data tables and conservative default settings. It does not scan, download packages, create baselines, schedule a task, or contact an external service.

## First scan

1. Open **DiffVerity → Scan**.
2. Choose **Run Integrity Scan**.
3. Keep the page open while the progress indicator advances. Components and files are processed in bounded batches.
4. Choose **Cancel Scan** if needed. Cancellation cleans the current temporary source package and releases the scan lock.
5. After completion, review **DiffVerity → Changes**.

Only one full scan can run at a time. A stale lock expires automatically after 30 minutes.

## Understanding trust sources

| Trust source | What it proves | What it does not prove |
|---|---|---|
| Officially Verified | A file matches an authoritative checksum for the exact installed version. | That the software has no vulnerability or the whole site is secure. |
| Official Source Available | A file matches a manifest generated from an exact-version official WordPress.org package. | That no compromise exists elsewhere. |
| Local Baseline | A file has or has not changed since an administrator-created snapshot. | That the snapshot was clean, original, authentic, or malware-free. |
| No Trusted Source | No independent source could be established. | This is not itself evidence of compromise. |
| Not Verifiable | Verification could not be completed, for example because of a network or malformed-response failure. | This is not an integrity mismatch. |

## Core verification

DiffVerity requests WordPress core checksums for the exact installed WordPress version and locale. It checks expected root files plus files in `wp-admin` and `wp-includes`. It does not treat arbitrary hosting-specific root files as malware and does not scan `wp-content/uploads`.

Possible results include Verified, Modified, Missing, Unexpected, Skipped, and Source Unavailable. An unexpected core-directory file is evidence that the file is outside the official manifest; it is not automatically labeled malicious.

## Plugin verification

For eligible WordPress.org plugins, DiffVerity requests the checksum manifest for the exact installed slug and version. It never substitutes the latest version. If checksums are unavailable but the exact official package exists, it may build a temporary package manifest and label the evidence **Official Source Available**.

The plugin package is retrieved only over HTTPS from approved WordPress.org hosts. It is never activated, included, or executed. The temporary ZIP and extracted directory are removed after use.

## Theme verification

WordPress.org themes are compared with a manifest generated from the exact-version official theme ZIP. Theme PHP is treated as untrusted comparison data and is never executed. If the exact version cannot be retrieved, DiffVerity reports source availability separately from file integrity.

## Local Baselines

Use a Local Baseline for premium, private, or custom components that do not have an independently verifiable WordPress.org source.

1. Open **DiffVerity → Baselines**.
2. Read the warning carefully.
3. Select one plugin or theme.
4. Choose **Create Local Baseline** and confirm the deliberate action.

The baseline stores relative paths, file sizes, SHA-256 hashes, optional normalized hashes, component version, and timestamp. It does not store full source copies. The default history is three baselines per component and can be set from one to ten.

If the component version changes, DiffVerity treats the baseline as outdated and asks for review. It never automatically trusts an update or silently replaces an older baseline.

## File statuses

| Status | Meaning |
|---|---|
| Verified | The local hash matches the chosen source. |
| Modified | The file exists in both locations, but raw contents differ. |
| Missing | The trusted source contains the file; the installed component does not. |
| Unexpected | The installed component contains a file absent from the official manifest. |
| Baseline Changed | A file differs from the Local Baseline, or the component version changed. |
| Baseline Added | A local file was added after the baseline. |
| Baseline Missing | A baseline file is no longer installed. |
| Source Unavailable | A trusted comparison could not be established. |
| Skipped | DiffVerity deliberately did not follow or read the file, for example a symbolic link. |
| Soft Difference | Raw bytes differ, but text becomes identical after BOM/line-ending normalization. |

Statuses report evidence, not intent. A modified PHP file is important to review, but a checksum difference alone cannot determine whether the cause was legitimate customization, deployment, an incomplete update, an unintended modification, or compromise.

## Viewing diffs

For a Modified or Soft Difference text file backed by an official source:

1. Open **DiffVerity → Changes**.
2. Choose **View Diff**.
3. Review **Original / Trusted Source** beside **Local File**.

Source is escaped and displayed as text. PHP, HTML, JavaScript, shortcodes, and SVG markup cannot execute in the diff viewer. Files larger than the configured limit (1 MB by default), unreadable files, missing files, and binary files show hashes, sizes, and a reason instead of a line diff.

Local Baselines do not retain original source lines, so baseline-only changes show hash evidence rather than an original-line diff.

## Soft Differences and Strict comparison

Normal mode distinguishes raw-byte mismatches caused only by CRLF/LF conversion or a UTF-8 BOM. These remain visible as **Soft Difference** and are never changed to Verified.

Enable **Strict comparison** under **DiffVerity → Settings** to classify every raw mismatch as a full change.

## History

**DiffVerity → History** retains 20 scans by default, configurable from 1 to 100. It shows duration, progress, and links to stored file evidence. When two completed scans exist, the summary shows new, resolved, and unchanged difference identities.

History contains hashes and metadata, not complete source-code copies.

## Exports

On the Changes screen, export the selected scan as JSON, CSV, or TXT. Reports contain component identity, exact installed version, relative path, status, trust source, hashes, sizes, severity, message, and timestamp. They do not contain full source code, absolute server paths, `wp-config.php` content, database credentials, salts, cookies, or API keys.

## Settings

Conservative defaults are:

- 200 files per request batch;
- 1 MB maximum interactive text diff;
- 20-second HTTP timeout;
- 50 MB maximum official package;
- 20 retained scans;
- 3 retained baselines per component;
- Strict comparison off;
- uninstall data deletion off.

Exclusions accept only controlled component identifiers (`plugin:slug`, `theme:slug`), relative paths, or file extensions. Absolute paths and traversal segments are rejected. Excluded PHP files are never hidden by default.

## Troubleshooting

**Source Unavailable** — Confirm outbound HTTPS access to `api.wordpress.org` and `downloads.wordpress.org`. DiffVerity never retries with disabled certificate validation.

**Version unavailable** — The installed release may no longer be present on WordPress.org. Do not compare it with “latest”; update deliberately or use a clearly labeled Local Baseline.

**Permission denied** — DiffVerity can scan read-only plugin and theme directories, but the PHP process must be able to read a file to hash it.

**Large package rejected** — Increase the package limit only after confirming the expected exact-version download. The limit protects temporary disk space.

**Symbolic link skipped** — DiffVerity does not follow symlinks because they can escape an approved component root.

## Privacy

There is no DiffVerity cloud service, telemetry, analytics, tracking, marketing call, or source-code upload in version 1.0.0. Administrator-triggered verification contacts only documented WordPress.org services. See the plugin’s `readme.txt` for the precise endpoints and request disclosure.

## Limitations

DiffVerity does not scan the database, uploads, arbitrary directories, server configuration, or runtime behavior. It has no malware signatures, vulnerability database, firewall, WAF, login protection, repair, quarantine, backup, remote monitoring, or AI analysis. Matching official files cannot guarantee overall site security.
