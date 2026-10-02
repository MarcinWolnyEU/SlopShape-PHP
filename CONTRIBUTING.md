# Contributing

This project takes issues, not pull requests. Pull requests will be closed
without review, whatever they contain. If you found a bug, have a question or
want something changed, open an issue at
https://github.com/MarcinWolnyEU/SlopShape-PHP/issues/new/choose and pick the
template that fits:

- **Bug report**: the package crashed, returned a wrong score, or behaved
  differently from the README.
- **Question**: how to use it, what a number means, or whether it suits your
  case.
- **Other**: feature ideas, documentation problems, anything else.

Security problems don't go in public issues. See `SECURITY.md`.

## Writing a useful bug report

The score comes from text you send, a model you pick and a bridge you wrote, so
a report without those three is usually impossible to reproduce. Include:

- the package version (`composer show marcinwolnyeu/slopshape-php`) and your PHP
  version (`php -v`);
- the text you scored, or the smallest piece of it that still shows the
  problem. Remove anything private first;
- the model and bridge you used, and the raw model reply if you have it
  (`RecordingBridge` captures it);
- what you expected, and what you got: the full `Score` output
  (`json_encode($score, JSON_PRETTY_PRINT)`) or the exception with its trace.

A wrong-looking score is not always a bug. The detector refuses to label short,
non-Latin or unusual texts on purpose, and five of its seven labels are
refusals. If `$score->refusal()` explains the result, it's working as designed.
Ask a question instead, if the reason isn't clear.

## Asking for a change

Say what you're trying to do, not only the change you have in mind. Some
requests can't be accepted, whoever asks:

- **Changes to the score's meaning:** the defaults in `Config` are documented
  in the README: `judgementFloorWords` comes from the SlopShape release data and
  the rest are local choices. Making the detector more confident is a research
  change, not a bug fix.
- **Edits to `resources/instrument.json`:** it is derived from the SlopShape
  release and carries the SHA-256 of each source file. If the release changes,
  the file is regenerated from it with
  `php tools/build-instrument.php /path/to/slopshape`.
- **Runtime dependencies:** there are none, so the offline part of the package
  keeps working in locked-down environments.

## Licences

This is a port of noncommercial code, and the instrument data it ships is
all-rights-reserved by the study's authors. Read the upstream
[`LICENSE`](https://github.com/pulse-energy-eu/slopshape/blob/main/LICENSE)
before asking for anything that would bundle more upstream data. Questions about reusing the
study material itself go to its copyright holder, `jochen@sitefire.ai`, not to
this project.

## For the maintainer

Running the checks locally:

```bash
composer install
composer check
```

`composer check` is the whole gate: `composer validate --strict`, PHP-CS-Fixer,
PHPStan at level max with strict rules, Rector, the dependency checker and the
tests. CI runs the same commands on PHP 8.3, 8.4 and 8.5, and also requires
100% line coverage and a 100% mutation score from Infection.

Rules the code follows:

- **If a change makes the score mean something different, it needs a new
  released number or a new test that pins the arithmetic.**
  `tests/Unit/GoldenTest.php` pins the formula with hand-derived values.
- **`composer cs:fix` decides style.** Rules switched off in
  `.php-cs-fixer.dist.php` and skipped in `rector.php` carry their reason next
  to them.
- **No network in tests.** `tests/Fixtures/FakeBridge.php` is the seam.
- **A bug fix starts with a test that fails.**

Releasing:

1. Update `CHANGELOG.md` under `Unreleased`.
2. Tag `vX.Y.Z`.
3. Verify `composer check` on the tagged commit.
4. Nothing in the public API list in `CHANGELOG.md` changes without a major
   bump.
