# DiffVerity roadmap

This roadmap focuses on making the existing comparison workflow easier to verify and maintain. It does not change the plugin's read-only scope. No dates are set until the work and test capacity are agreed.

## 1. Automated regression coverage

- Add isolated tests for checksum parsing, component classification, path normalization, diff escaping, CSV formula handling, and result pagination.
- Add WordPress integration tests for activation, table upgrades, scan cancellation, baseline retention, exports, and uninstall behavior.
- Exercise the minimum supported WordPress and PHP versions, plus the current stable releases.

**Ready when:** the suite runs in CI on every change, covers the main scanner and persistence paths, and reports failures without calling live WordPress.org services.

## 2. Source-provider resilience

- Build deterministic fixtures for checksum, package, timeout, malformed-response, and unavailable-source cases.
- Verify that every result label and exported record retains the source version and comparison evidence needed to explain the result.
- Keep network access limited to documented WordPress.org endpoints and keep installed file contents local.

**Ready when:** tests cover successful, partial, and failed provider responses, and each outcome produces a truthful status without converting an unavailable source into a mismatch.

## 3. Large-site and recovery checks

- Measure scan time and memory with large synthetic component trees and many file results.
- Exercise expired locks, interrupted requests, repeated cancellation, history retention, and pagination at page boundaries.
- Review the bounded file, package, and diff limits against those measurements.

**Ready when:** documented resource limits hold under the agreed test fixtures, scans can be resumed or cancelled safely, and old history is pruned without removing current records.

## 4. Admin usability and accessibility

- Test the scan, filter, pagination, export, baseline, and evidence-dialog paths with keyboard and screen-reader checks.
- Check tables and forms at narrow admin widths, browser zoom, and high-contrast settings.
- Use support feedback to prioritize improvements to evidence explanations and error messages.

**Ready when:** all actions remain keyboard operable, focus is visible and restored after dialogs, errors are announced, and no required data is hidden at narrow widths.

## 5. Release discipline

- Keep the plugin package free of development-only files and verify its contents against the WordPress.org SVN release layout.
- Run PHP lint, Plugin Check, readme validation, and the supported WordPress/PHP integration matrix before a release.
- Compare the package, Git tag, and WordPress.org SVN tag after publication.

**Ready when:** release checks are recorded with versions and outcomes, and the published tag and downloadable package contain the same reviewed source.

## Product boundary

DiffVerity reports file comparison evidence. It does not declare a site safe or compromised, repair files, delete files, block requests, or replace a malware scanner. Any future expansion should keep those limits visible and require a separate product decision.
