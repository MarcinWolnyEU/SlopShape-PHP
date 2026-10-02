# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | yes |
| < 1.0 | no |

Security fixes are applied to the latest release.

## Reporting a vulnerability

Please report privately rather than opening a public issue:

- [GitHub Security Advisories](https://github.com/MarcinWolnyEU/SlopShape-PHP/security/advisories/new) on the repository, or
- email marcin@marcinwolny.net.

Include what you found, how to reproduce it, and the version or commit. You
should get an acknowledgement within a few days and a fix or an explanation
within a reasonable time.

## What a security issue means in this package

The threat model is narrow, because most of this package is not attack
surface:

- **The prompt is untrusted text.** Document content reaches a language model.
  This package sends the document's title, subtitle and body plus the frozen
  answer menus, and nothing else: no other metadata, no instrumentation, no
  user data. The document goes inside a `<pasted_content>` block with a random
  id per prompt, and the model is told to treat it as data. That makes prompt
  injection harder but cannot rule it out, since obeying the text is up to the
  model. A hostile document can still steer a reading.
- **The answer is untrusted input.** It is parsed, forced onto a frozen menu
  and length-capped, so a hostile answer cannot widen the score's influence; it
  can only choose among the instrument's own values. Bounds are enforced on
  every gap, signal and `exp()` argument.
- **The instrument file is trusted configuration.** If you load one from
  somewhere you do not control, you have granted that file control of the
  score. Load your own copy, not a downloaded one.
- **No network, no filesystem writes, no dynamic code execution.** The only I/O
  this package does is reading the instrument file. Everything else is your
  bridge's business.
- **Privacy:** document text leaves your process through your bridge, and
  nowhere else. If that matters for your jurisdiction, that is the line to
  check.
