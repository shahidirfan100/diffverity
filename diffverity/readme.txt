=== DiffVerity – File Integrity & Source Diff ===
Contributors: shahidirfan100
Tags: file integrity, checksum, source diff, modified files, plugin integrity
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify core, plugin, and theme files and inspect exact changes against trusted sources or local baselines.

== Description ==

DiffVerity is a focused, read-only integrity investigation tool. It answers what changed, where it changed, what comparison source was used, and whether that source is independently authoritative.

It does not claim that a changed file is malware, and it does not claim that an unchanged installation is secure. File differences can result from legitimate customization, deployment tooling, an incomplete update, unintended modification, or compromise.

= Features =

* Verifies WordPress core against checksums for the exact installed version and locale.
* Verifies eligible WordPress.org plugins against exact-version checksums.
* Compares WordPress.org themes with exact-version official packages.
* Supports explicit, hash-only Local Baselines for private, premium, and custom plugins/themes.
* Separates Officially Verified, Official Source Available, Official Core Bundle, Local Baseline, No Trusted Source, and Not Verifiable states.
* Recognizes current and recent official plugin/theme copies bundled with WordPress, including older wp-content variants legitimately preserved during core updates.
* Detects verified, modified, missing, unexpected, baseline-added, baseline-missing, and skipped files.
* Identifies line-ending and UTF-8 BOM-only changes as Soft Differences while retaining the raw mismatch.
* Shows escaped, human-readable source diffs for eligible text files.
* Shows hashes and sizes instead of attempting line diffs for binaries or large files.
* Runs on demand in resumable, resource-bounded admin batches with cancellation and scan locking.
* Scans all components or one selected WordPress core, plugin, or theme component.
* Stores capped scan history and baseline history in dedicated, indexed tables.
* Exports JSON, CSV, and text reports without full source code or absolute server paths.
* Adds no frontend assets, telemetry, analytics, tracking, firewall, automated repair, or malware claims.

= Trust sources =

**Officially Verified** means the installed file was compared with an authoritative checksum for the exact installed version.

**Official Source Available** means DiffVerity retrieved an authoritative exact-version WordPress.org package and generated a comparison manifest.

**Official Core Bundle** means the file matches a checksum from a current or recent official WordPress distribution. WordPress can change default-theme metadata or bundled plugin code without changing the component's own version, and core updates preserve existing wp-content files. DiffVerity records the originating WordPress bundle version for exact evidence and diffs.

**Local Baseline** means an administrator deliberately recorded local SHA-256 hashes. Local baselines prove that files changed after the baseline was created. They do not prove that the baseline itself was clean or authentic.

**No Trusted Source** means no independent comparison source could be established. This is not itself a security warning.

**Not Verifiable** describes a source that could not be checked, for example because of a network or malformed-response failure.

== External services ==

DiffVerity contacts WordPress.org only when an administrator runs a scan or requests an exact source diff.

* `api.wordpress.org` is used to request checksums for the installed WordPress version/locale and, only when a bundled component header matches neither the component package nor the current bundle, a bounded newest-to-oldest set of recent WordPress minor releases.
* `downloads.wordpress.org/plugin-checksums/` is used to request eligible plugin checksums for the exact installed slug and version.
* `downloads.wordpress.org/plugins/`, `downloads.wordpress.org/theme/`, and `downloads.wordpress.org/release/` are used to retrieve exact-version packages when package comparison or original-line display is needed.
* `core.svn.wordpress.org` is used only for an administrator-requested diff of a core-bundled plugin/theme file. DiffVerity retrieves that one file from the recorded WordPress tag instead of downloading the complete WordPress release archive.

Requests send the component slug/version or WordPress version/locale, a DiffVerity user-agent, and the site URL included in that user-agent. DiffVerity does not send local source code, database content, customer information, admin usernames, or site content. Requests use HTTPS with certificate verification. Redirects to unrelated hosts are not followed.

Checksum-verified single-file source responses are cached under the server's private temporary directory for up to six hours to make repeated diff actions fast. Cache filenames are hashes, cached files use a non-executable `.source` extension and restrictive permissions, and stale entries are removed automatically.

WordPress.org privacy policy: https://wordpress.org/about/privacy/

== Installation ==

1. Upload the `diffverity` folder to `/wp-content/plugins/`, or install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate DiffVerity.
3. Open DiffVerity > Scan.
4. Choose all components or one specific core, plugin, or theme target, then select **Run Integrity Scan**.
5. Review differences under DiffVerity > Changes.

Activation does not scan, download a package, create a baseline, schedule a task, or contact an external service.

== Screenshots ==

1. File-level comparison results show each status, trust source, and last-checked time.
2. The integrity overview summarizes the latest scan and explains the evidence labels.
3. Settings show resource limits and controlled exclusion options.

== Frequently Asked Questions ==

= Does a modified file mean my site is hacked? =

No. It means the file differs from the comparison source. Legitimate customizations, deployment processes, incomplete updates, or malicious changes can all cause differences. DiffVerity reports the evidence so the administrator can investigate.

= What is an official verification? =

It is an exact-version comparison with authoritative checksums supplied through WordPress.org infrastructure. DiffVerity never silently compares an older installation with the latest release.

= What is a local baseline? =

A Local Baseline is an administrator-created set of SHA-256 hashes and file metadata. It can identify later changes but cannot establish whether the original snapshot was clean or authentic.

= Can DiffVerity verify premium plugins? =

It cannot independently verify a private vendor source. You can create a Local Baseline to monitor later changes. That result remains clearly labeled as a baseline comparison.

= Can DiffVerity repair or delete changed files? =

No. DiffVerity is read-only and does not modify WordPress core, plugin, or theme files. Use the evidence report to investigate, then use WordPress's normal update or reinstall tools if you decide a component should be replaced.

= Does it upload my source code? =

No. Source code is read locally for hashing and optional display. It is not uploaded to a DiffVerity service; no such service exists.

= Why does a plugin show as unverified? =

The exact version may not exist on WordPress.org, the component may be private, or a network/source response may have failed. DiffVerity separates those lookup failures from integrity mismatches.

= Why can an older default theme or Hello Dolly differ after a WordPress update? =

WordPress core updates preserve wp-content. WordPress also sometimes changes a bundled default theme's compatibility metadata or Hello Dolly's code without changing that component's own version. DiffVerity checks a bounded set of recent official WordPress bundle manifests and records the exact matching bundle version rather than assuming the current core package is the only valid source.

= What is a soft difference? =

Raw bytes differ, but the textual content becomes equivalent after only UTF-8 BOM and line-ending normalization. The file is not labeled Verified because its raw checksum genuinely differs.

= Why did my baseline become outdated after an update? =

A baseline belongs to one recorded component version. DiffVerity does not automatically trust replacement files after an update. Review the update, keep the old history if useful, and deliberately create a new baseline.

= Does it scan uploads? =

No. DiffVerity scans only WordPress core, plugins, and themes. It does not recurse through uploads or arbitrary server directories.

== Privacy ==

DiffVerity includes no telemetry, analytics, tracking, advertising, marketing requests, or vendor cloud API. Its only external communication is the documented, administrator-triggered authoritative WordPress.org lookup described above.

== Limitations ==

File integrity does not measure overall website security. An official package can still contain a software vulnerability, and compromise can exist in the database, uploads, server configuration, or another unscanned location. DiffVerity does not make malware-free, clean, authentic, or overall-security guarantees.

Local Baselines retain hashes and metadata rather than full source copies, so original-line diffs are unavailable for baseline-only changes.

DiffVerity does not repair or delete files. It deliberately keeps investigation separate from remediation because changed files may be legitimate local additions or customizations.

== Changelog ==

= 1.2.2 =

* Replaced the directory icon and banners with an original, flat file-comparison mark.
* Simplified the admin screens to follow WordPress controls and improve narrow-screen reading.
* Added keyboard focus management and progress announcements to the evidence dialog and scan controls.
* Resumes an active scan when its administrator returns to the Scan screen.
* Rejects plugin checksum manifests with a different identity or without the selected entry file.
* Limits root-level single-file plugin scans and bundled-core evidence to the selected entry file.
* Aligns translation calls with the existing `diffverity` plugin slug.
* Added screenshots of the real Changes, Overview, and Settings screens.

= 1.2.1 =

* Fixed history comparisons so every result is processed in bounded pages instead of stopping after 500 files.
* Streamed complete JSON, CSV, and text exports without loading the entire scan into memory.
* Added paginated file results so scans larger than 500 files remain fully accessible.
* Added stable result ordering and protected CSV fields from spreadsheet-formula interpretation.

= 1.2.0 =

* Removed all file-repair actions so the plugin remains read-only and never writes to WordPress core, plugin, or theme directories.
* Changed the text domain to match the assigned WordPress.org plugin slug.
* Corrected the WordPress.org contributor username.

= 1.1.4 =

* Fixed false Soft Difference results when WordPress.org publishes multiple accepted hashes for one plugin file.
* Preserved every official MD5 or SHA-256 variant returned by the plugin checksum service instead of coercing checksum arrays to a single invalid value.
* Recorded the matching official checksum variant in scan evidence.

= 1.1.3 =

* Fixed false Modified results for older official wp-content variants preserved after WordPress core updates.
* Added bounded historical core-bundle checksum matching for recent WordPress minor releases.
* Verified Twenty Twenty-Three 1.6 variants from WordPress 6.7, 6.8, 6.9, and 7.0 without treating compatibility-header changes as local modifications.
* Verified the official Hello Dolly `die();` variant bundled with WordPress 6.8 through 7.0.
* Replaced full WordPress ZIP downloads for core-bundle diffs with one checksum-verified source-file request.
* Added a private six-hour verified source cache so repeated diff views do not repeat the request.
* Recorded the originating WordPress source version in scan evidence and exports.

= 1.1.2 =

* Fixed core-bundle verification for an individual file when another file in the bundled plugin or theme was locally customized.
* Compared modified bundled components, including Hello Dolly, with the exact WordPress-release copy so the diff shows only the remaining local changes.
* Added clickable latest-scan components on Overview and clickable component names in Changes.

= 1.1.1 =

* Fixed false Modified results for default themes and bundled plugins such as Twenty Twenty-Five and Hello Dolly when the WordPress release bundle differs from the separately published component package.
* Added exact-version Official Core Bundle checksum evidence without downloading the full WordPress release package.
* Added component-header verification before accepting bundled file checksums.
* Improved single-line and empty diff handling so byte differences never open as a blank text diff.

= 1.1.0 =

* Added a scan-target selector for all components, WordPress core, or one installed plugin or theme.
* Fixed settings sanitization warnings for excluded components and paths.
* Reworked custom-table identifiers and documented unavoidable owned-table queries for clean standards analysis.

= 1.0.0 =

* Added official core integrity verification for the exact version and locale.
* Added exact-version WordPress.org plugin checksum verification and official package fallback.
* Added exact-version official theme package comparison.
* Added hash-only Local Baseline monitoring with version awareness and capped history.
* Added modified, missing, unexpected, baseline, skipped, source-unavailable, and Soft Difference statuses.
* Added escaped text diffs and safe metadata-only handling for binary and large files.
* Added bounded AJAX scan batches, locking, cancellation, history, filters, and JSON/CSV/TXT exports.
* Added controlled exclusions, conservative resource limits, multisite-safe capability checks, and opt-in uninstall cleanup.

== Upgrade Notice ==

= 1.2.2 =

Reworked the admin presentation, accessibility, and directory media while preserving the 1.2.1 feature set.
