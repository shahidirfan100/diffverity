# DiffVerity User Guide

This guide covers DiffVerity 1.2.1, a read-only WordPress file-integrity investigation tool.

## Install

Install from WordPress.org or the release ZIP linked in README. Activate it from Plugins, then open DiffVerity in the WordPress admin. Activation creates owned storage and defaults; it does not start a scan, download packages, create a baseline, schedule work, or make an external request.

## Run an integrity scan

1. Open **DiffVerity → Scan**.
2. Choose all components or one core, plugin, or theme.
3. Run **Integrity Scan**.
4. Keep the page open while bounded batches process.
5. Review **DiffVerity → Changes**.

Only one full scan runs at a time. Cancelled or expired lock cleanup is handled automatically.

## Trust labels

- **Officially Verified:** exact installed version and authoritative checksum.
- **Official Source Available:** exact-version official package manifest.
- **Official Core Bundle:** recent official WordPress distribution copy.
- **Local Baseline:** administrator-created SHA-256 snapshot.
- **No Trusted Source:** no independent source was established.
- **Not Verifiable:** source, response, or network failure.

Trust labels describe source evidence; they are not a complete site-security claim.

## File statuses

**Modified** means both files exist but raw contents differ. **Missing** means the trusted source has a file absent locally. **Unexpected** means a local file is absent from the trusted manifest. **Soft Difference** means only line endings or a BOM differ after normalization. **Skipped** means the file was deliberately not followed or read, such as a symlink. Review evidence before taking action.

## Local baselines

Use baselines for private, premium, or custom components. Open **Baselines**, select a plugin or theme, read the warning, choose **Create Local Baseline**, and confirm. The baseline stores relative paths, sizes, SHA-256 hashes, component version, and timestamp—not source copies or proof of authenticity. A version change makes an old baseline outdated.

## View source diffs

Open **Changes → View Diff** for a text result with an official source. Source is escaped for display. Large, binary, unreadable, or unavailable files show hashes, sizes, and the reason a text diff is unavailable.

## Export reports

Export JSON, CSV, or text reports. They contain component identity, version, path, status, trust, hashes, sizes, severity, message, and timestamp—not source, absolute paths, credentials, cookies, salts, or API keys.

## Settings and limitations

Settings bound batch size, diff size, timeout, package size, scan history, and baseline history. Exclusions accept controlled identifiers, relative paths, and extensions; absolute paths and traversal are rejected.

DiffVerity has no cloud service, telemetry, analytics, tracking, advertising, or vendor API. It makes only documented WordPress.org HTTPS requests during administrator-triggered verification. It does not scan uploads, arbitrary directories, the database, server configuration, or runtime behavior. Evidence is not a malware, vulnerability, or overall-security verdict.
