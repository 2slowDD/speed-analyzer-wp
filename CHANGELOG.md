# Changelog

All notable changes to Speed Analyzer are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.18.1] - 2026-03-11
### Changed
- Replaced text with wpsa-admin-cwv dahicons 
- Added the Code Unloader reference

### Fixed
- Removed wpsa_speed column-wpsa_speed from the product pages

---

## [1.18] - 2025-06-03
### Added
- Response headers functionality in the Module 1 dropdown section

### Changed
- License manager reworked

### Fixed
- Load test # bug where N/A values in Module 2 loaded former test data instead of displaying N/A

---

## [1.17.9]
### Added
- CWV status column to Posts/Pages list
- CWV status section to PDF report
- Schedule tests alert emails: regression and absolute threshold functions

### Fixed
- Bug where CWV scope was returned as URL even when scope was `origin`

---

## [1.17.8]
### Fixed
- CWV display on the main test screen

---

## [1.17.7]
### Added
- Core Web Vitals (CWV) test metrics
- 100+ tests info message

### Fixed
- Schedule tests "Add another" button

---

## [1.17.6]
### Added
- Speed Analyzer column in the Posts and Pages list showing the latest performance score, CWV metrics, and direct links to re-test or open reports

### Changed
- Minor UI tweaks

---

## [1.17.5]
### Added
- Module 3 — mobile and desktop screenshot images of the tested URL
- Diagnostics for Module 3 added to scheduled test emails

### Changed
- Scheduled test email redesigned
- Module order rearranged
- PDF report updated to include screenshot images

---

## [1.17.3]
### Added
- DB size to the tested metrics
- Second "Generate PDF report" button on the main screen

### Fixed
- Module 5: Diagnostics section style was misaligned

---

## [1.17.2]
### Fixed
- Module 5: Diagnostics section was not loading
- Minor styling improvements

---

## [1.17.1]
### Added
- `S` badge on `#wpsa-tested-url` when loading results from Scheduled tests
- Direct link from Scheduled tests view to the Compare Results section

---

## [1.17]
### Added
- Schedule tests functionality — run audits on a timetable
- Test results delivery via email
- Compare Results filtering reworked for scheduled (`S`) tests

---

## [1.16.6]
### Fixed
- Module 6 Recommendations section icons
- PDF report page 6 tweaks
- Prevent running a new test before the current one finishes

---

## [1.16.5]
### Added
- CLS (Cumulative Layout Shift) metric
- INP (Interaction to Next Paint) metric

### Changed
- Module 7 Conclusion section UI tweaked
- PDF report updated

---

## [1.16.4]
### Added
- PSI Insights panel to Module 2 and 3

### Changed
- Compare two tests chart-card reorganized
- PDF report styling tweaked

### Removed
- External admin notices from the main plugin page (CSS suppression)

---

## [1.16.3]
### Added
- Load test by # functionality
- Diagnostics section expanded to show up to top 10 items

### Changed
- Compare Results charts rearranged
- PDF report tweaked
- Other minor tweaks

---

## [1.16.2]
### Added
- Onload JS and Onload CSS metrics

---

## [1.16.1]
### Fixed
- N/A now shown instead of 0 in Compare Results when data is absent
- Bug where each test counted as two toward the daily limit

---

## [1.16]
### Added
- Compare Results (A/B testing) functionality

### Fixed
- PDF report: Persistent Object Cache not showing as present on some tests

---

## [1.15.1]
### Changed
- Minor visual change on Module 1 (green notice only shown when relevant)

---

## [1.15]
### Added
- Module 3 point 4.5: number of active plugins, PHP version, and DB server version
- Above data included in PDF report

### Changed
- Cloudflare Workers updated for different server region collection

---

## [1.14]
### Added
- Top 10 autoloaded options by size in Module 3
- Agency plan: branding options for PDF report (custom header, optional CTA box)

### Fixed
- Minor bugs

---

## [1.13]
### Fixed
- Modules 2 and 5 not finishing properly on slower websites

---

## [1.12]
### Added
- Premium plan perks
- Additional Cloudflare Workers for redundancy

---

## [1.11]
### Added
- Additional worker and API key for redundancy

### Fixed
- Minor bug fixes

---

## [1.10]
### Added
- PDF reporting with color coding, explanations, and guide links

### Changed
- More robust Cloudflare Workers code
- Minor tweaks and style changes

---

## [1.09]
### Added
- Left and right admin sidebars
- License panel outline

---

## [1.08]
### Fixed
- Daily limit counting reworked to prevent double reduction

---

## [1.07]
### Changed
- Plugin slug changed
- External service disclosure added to readme
- Minor fixes in `helpers.php` and `modules.php`

---

## [1.06]
### Changed
- Version bumped to avoid trademark issues for WordPress.org release
