# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-02

Initial release

### Known limitations

- At the default calibration and reading confidence a document of 800 words or more cannot reach `LikelyHuman` (P[AI] bottoms out at 0.2002, against a 0.20 threshold).
- Calibrated on English commercial B2B blog posts only. Non-Latin scripts are reported as out of range; other Latin-script languages are not caught, and whether the released gaps hold for them is unmeasured.

[1.0.0]: https://github.com/MarcinWolnyEU/SlopShape-PHP/releases/tag/v1.0.0
