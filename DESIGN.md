# DiffVerity design direction

## Product read

DiffVerity compares installed files with checksums, source packages, and administrator-created baselines. Its interface should help an administrator understand the evidence and the limits of each comparison. It should not imply that a difference proves compromise or that a baseline proves a clean state.

## Identity

- **Motif:** two aligned file records with one visibly different line. The mark describes comparison work directly.
- **Voice:** exact, calm, and explicit about uncertainty.
- **Palette:** charcoal ink and warm paper form the brand base. A muted rust line marks a changed record. Green, amber, and red remain reserved for status meaning in the interface.
- **Typography:** inherit the WordPress admin system font. Use a system monospace face only for literal paths, hashes, and source diffs.
- **Layout:** use WordPress controls and tables, with compact metric bands and flat section borders. Keep spacing tied to reading groups and task steps.
- **Motion:** none by default. A short progress transition may remain because it helps track a running scan; respect reduced-motion preferences.

## Design read

- **Energy:** 2/5. The plugin is an inspection tool; clear evidence matters more than spectacle.
- **Rhythm:** 2/5. Keep screens mostly linear, with a compact summary before detailed rows.
- **Motion:** 0/5. Scans report progress without decorative movement.
- **Focal point:** the current task or the evidence table.
- **Accent:** one rust line identifies the changed record in the mark and banner.
- **Reason:** a restrained record motif is specific to file comparison, while WordPress-native controls keep familiar interactions and reduce visual noise.

## Boundaries

- No gradients, glow, external fonts, decorative dashboards, invented usage statistics, or security guarantees.
- Preserve the read-only boundary. DiffVerity must not write to WordPress core, plugin, or theme files.
- Keep status color paired with its written label so color is never the only signal.
