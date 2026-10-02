# SlopShape-PHP

[![CI](https://github.com/MarcinWolnyEU/SlopShape-PHP/actions/workflows/ci.yml/badge.svg)](https://github.com/MarcinWolnyEU/SlopShape-PHP/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/marcinwolnyeu/slopshape-php)](https://packagist.org/packages/marcinwolnyeu/slopshape-php)
[![License: PolyForm Noncommercial](https://img.shields.io/badge/license-PolyForm%20NC%201.0.0-blue)](LICENSE.md)

**Structural AI-text detection for PHP.** Give it a text, plus a model callback if you want extra accuracy, and get back a probability of AI authorship, a confidence, and the evidence behind both.

Use it to add an AI-slop check to your CI pipeline, or to any system that needs a plain signal for whether a text smells like slop. The detection method comes from @jochenmadler's September 2026 paper, [arXiv:2609.15369 SlopShape: Identifying AI-Generated Commercial Web Content](https://arxiv.org/abs/2609.15369).

## Install

```bash
composer require marcinwolnyeu/slopshape-php
```

> **You cannot use this package on a commercial project.** The code is PolyForm Noncommercial 1.0.0 license.

## Usage

Package requires PHP 8.3+ with `ext-mbstring`

### Plain

Without callback, it runs fully offline and scores only what it can count itself. It's much faster and free, but suffers from reduced accuracy.

```php
$score = (new Detector())->detect($text);   // a 720-word text

$score->probabilityAi;     // float, e.g. 0.926
$score->confidence;        // float, e.g. 0.498
```

### Minimalistic solution

When you only need the number, to sort, filter or threshold, the `probabilityAi()` function skips the `Score` object:

```php
use function SlopShape\probabilityAi;

if (probabilityAi($text, $bridge) > 0.8) {
    // ...
}
```

#### 2. OpenAI, in a closure

Uses [openai-php/client](https://github.com/openai-php/client) (PHP 8.2+; it also needs a PSR-18 HTTP client such as Guzzle). It is not a dependency of this package.

```bash
composer require openai-php/client guzzlehttp/guzzle
```

```php
use SlopShape\Detector;

$client = OpenAI::client(getenv('OPENAI_API_KEY'));

$detector = new Detector(static function (string $prompt) use ($client): string {
    $response = $client->responses()->create([
        'model' => 'gpt-6-luna',   // swap in any model you prefer
        'instructions' => 'You are a precise annotator. You answer with JSON only.',
        'input' => $prompt,
        'max_output_tokens' => 4000,
    ]);

    return $response->outputText ?? '';
});

$score = $detector->detect($text);

echo $score, "\n";
// 91/100  P[AI]=0.913  likely AI-generated  confidence 0.50  9/10 core values read
```

The closure returns the raw answer text. Let API errors propagate; the detector wraps them in `BridgeFailure`. The output above is illustrative; it depends on the model and the text.

#### 3. OpenRouter, as an `LlmBridge`

A named bridge class for [OpenRouter](https://openrouter.ai/docs/api-reference/chat-completion), which serves many providers' models behind one OpenAI-compatible endpoint. It needs only `ext-curl` and `ext-json`:

```php
use SlopShape\Bridge\LlmBridge;

final readonly class OpenRouterBridge implements LlmBridge
{
    public function __construct(
        private string $apiKey,
        private string $model = 'openai/gpt-6-luna',
        private int $timeoutSeconds = 120,
    ) {}

    public function complete(string $prompt): string
    {
        $curl = curl_init('https://openrouter.ai/api/v1/chat/completions');

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a precise annotator. You answer with JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 4000,
            ], JSON_THROW_ON_ERROR),
        ]);

        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (false === $body || 200 !== $status) {
            throw new RuntimeException(sprintf(
                'OpenRouter request failed (HTTP %d): %s',
                $status,
                false === $body ? curl_error($curl) : $body,
            ));
        }

        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return $data['choices'][0]['message']['content']
            ?? throw new RuntimeException('OpenRouter returned no answer: ' . $body);
    }
}
```

Score a text and print the probability with the evidence on each side:

```php
use SlopShape\Detector;

$detector = new Detector(new OpenRouterBridge(getenv('OPENROUTER_API_KEY')));

$score = $detector->detect($text);

printf("P[AI] = %.3f\n", $score->probabilityAi);

echo "\nEvidence for AI:\n";
foreach ($score->positiveFactors() as $factor) {
    printf("  %+0.4f  %s: %s\n", $factor->contribution, $factor->feature, $factor->value);
}

echo "\nEvidence for human:\n";
foreach ($score->negativeFactors() as $factor) {
    printf("  %+0.4f  %s: %s\n", $factor->contribution, $factor->feature, $factor->value);
}
```

```
P[AI] = 0.915

Evidence for AI:
  +0.8373  Conclusion Behavior: restated_thesis_or_reframe
  +0.7639  Functional Stage Inventory: summary_or_synthesis
  +0.4070  Freshness Signaling: modern_or_next_generation_framing
  +0.3910  Dominant Credibility Basis: confident_institutional_explanation
  +0.3166  Functional Stage Inventory: mechanism_explanation

Evidence for human:
  -0.3340  Article Length Class: short_under_800_words
```

Any HTTP error, or a reply with no answer in it, throws, so the detector reports a `BridgeFailure` instead of scoring an empty string. The output above is illustrative; it depends on the model and the text.

## What it does

SlopShape (Madler 2026, [arXiv:2609.15369](https://arxiv.org/abs/2609.15369)) found that discourse-level *structural* choices separate AI-generated from human-written commercial web content at 0.9695 macro-F1, that ten values carry much of that signal, and that the signal survives rewording. None of it is a word list: the features describe how a post is built, not which words it uses.

SlopShape-PHP applies those ten values to one document:

1. **Measure what can be measured.** Word count is countable, so it is counted and
   never asked about. (On a 674-word article, one model studied by the
   paper answered "800-1499 words".)
2. **Ask your model about the rest**, using the study's own stage-5 annotation
   prompt, the study's own frozen answer menus, and its "never invent values,
   never omit a feature" discipline. The rules and menus are transcribed, not
   paraphrased. Three things differ from the study's main run:

   - **A defence against prompt injection:** the post goes inside
     `<pasted_content id="...">` tags with a random id that is new for every
     prompt, and the model is told to treat it as data.
   - **One call, not eleven:** the study scored one dimension per call (11
     calls per post, with gemini-3.6-flash on a minimal thinking budget). This
     package asks for all eight answers in one call, which is the prompt
     template's single-call mode. The study checked that mode on a small sample
     and did not use it for the main run, where it answered 99.75% of items
     against 99.97%.
   - **A different cut-off for long posts:** the study sent the first 2,600
     words; this package sends the first `maxInputChars` characters (24,000 by
     default). The word count always uses the whole text.

   The title is left out: SlopShape v3 found that a title line moves some
   answers on its own.
3. **Force every answer onto its menu.** Exact matches pass through; near
   matches are repaired and the repair is recorded; an answer that matches
   nothing on the menu becomes a missing reading, not a guess, and *that* is
   recorded too.
4. **Weight the readings by released calibration** and sum them as log-odds:

   ```
   weight(gap)  = calibration * ln((1 + gap) / (1 - gap))
   contribution = confidence * weight * direction * signal
   P[AI]        = sigmoid(Σ contributions)
   ```

There is no learned model in here and no hidden state. Given a `Score` and the calibration constant, you can recompute the number by hand.

## Development

```bash
composer install
composer check     # validate + cs + phpstan + rector + deps + tests
```

| Command | What it does |
|---|---|
| `composer test` | Pest |
| `composer test:ci` | Pest in parallel, with coverage |
| `composer cs` / `cs:fix` | PHP-CS-Fixer |
| `composer stan` | PHPStan level max + strict rules |
| `composer rector` / `rector:fix` | Rector |
| `composer deps` | every used symbol is provided by a declared dependency |
| `composer test:infection` | mutation testing |

The suite pins the arithmetic to hand-derived golden numbers, so a change in the formula fails a test rather than quietly changing someone's report.

Bug reports and questions go in [issues](https://github.com/MarcinWolnyEU/SlopShape-PHP/issues/new/choose); pull requests are not accepted. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Licence

PolyForm Noncommercial 1.0.0, inherited from upstream rather than chosen. See [`LICENSE.md`](LICENSE.md).

## Citation

See [`CITATION.cff`](CITATION.cff). If you use this, cite SlopShape, not this package.
