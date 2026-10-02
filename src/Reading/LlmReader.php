<?php

declare(strict_types=1);

namespace SlopShape\Reading;

use SlopShape\Bridge\LlmBridge;
use SlopShape\Config;
use SlopShape\Exception\UnreadableResponse;
use SlopShape\Instrument\Feature;
use SlopShape\Instrument\Instrument;
use SlopShape\Text\Document;

/**
 * Asks a model to annotate the document against the instrument, once.
 *
 * One call for every feature the model is asked about, not one per feature and
 * not one per dimension. The study's main run scored one dimension per call; its
 * single-call mode was only checked on a small sample. The reply is a small JSON
 * object (under 1,000 characters), so the output budget matters only on a
 * reasoning model that spends it thinking (the bridge owns that, not this class).
 *
 * The response is parsed, not trusted. Code fences are stripped, the answer
 * JSON object is located by scanning for balanced braces, every answer is forced
 * onto its menu when it is close enough, and anything missing or unmatchable is
 * reported as missing rather than defaulted away.
 */
final readonly class LlmReader {
    public function __construct(
        private LlmBridge $bridge,
        private Config $config,
        private PromptBuilder $prompts = new PromptBuilder(),
    ) {
    }

    public function read(Instrument $instrument, Document $document): ReadBatch
    {
        $features = $instrument->promptFeatures();

        if ([] === $features)
        {
            return new ReadBatch();
        }

        $prompt = $this->prompts->build($instrument, $document->promptText($this->config->maxInputChars));
        $raw = $this->bridge->complete($prompt);
        $answers = $this->decode($raw, array_map(static fn (Feature $feature): string => $feature->id, $features));

        $missing = [];
        $offMenu = [];
        $readings = [];

        foreach ($features as $feature)
        {
            if (!\array_key_exists($feature->id, $answers))
            {
                $missing[] = $feature->id;

                continue;
            }

            // An answer that matches nothing on the menu is no reading at all,
            // not a reading of the first menu value: it is reported both as
            // missing and as off-menu, so the caller sees why.
            [$reading, $repairs] = $this->toReading($feature, $answers[$feature->id]);

            foreach ($repairs as [$given, $snappedTo])
            {
                $offMenu[] = new OffMenuAnswer($feature->id, $given, $snappedTo);
            }

            if (!$reading instanceof Reading)
            {
                $missing[] = $feature->id;

                continue;
            }

            $readings[$feature->id] = $reading;
        }

        return new ReadBatch($readings, $missing, $offMenu, [$prompt]);
    }

    /**
     * @param list<string> $expectedIds
     *
     * @return array<string, mixed>
     */
    private function decode(string $raw, array $expectedIds): array
    {
        $decoded = $this->locateJson($raw, $expectedIds);

        if (null === $decoded)
        {
            throw UnreadableResponse::notJson($raw);
        }

        return $decoded;
    }

    /**
     * Find the answer object in a response that may be wearing prose.
     *
     * Scans for balanced braces rather than matching a greedy `\{.*\}` regex,
     * so a code fence or a trailing remark after the object cannot swallow the
     * wrong span, and brace characters inside string literals do not unbalance
     * the count. Every top-level object that decodes is a candidate: a model
     * that echoes an example or quotes the post's own JSON before answering
     * must not have the echo win. The candidate holding the most expected
     * feature ids is the answer, and the last one wins a tie.
     *
     * Bytes throughout. JSON's structural characters are ASCII and never occur
     * inside a multi-byte UTF-8 sequence, so byte offsets are exact.
     *
     * @param list<string> $expectedIds
     *
     * @return array<string, mixed>|null
     */
    private function locateJson(string $raw, array $expectedIds): ?array
    {
        $raw = trim($raw);
        $length = \strlen($raw);
        $best = null;
        $bestHits = -1;
        $position = 0;
        $abandoned = 0;

        while ($position < $length && false !== ($start = strpos($raw, '{', $position)))
        {
            $end = $this->balancedEnd($raw, $start, $length);
            $candidate = null === $end ? null : $this->decodeObject(substr($raw, $start, $end - $start + 1));

            if (null === $end || null === $candidate)
            {
                // A stray "{" in the preamble must not hide the real object, but
                // retries are bounded so a wall of braces cannot make this quadratic.
                if (++$abandoned > 64)
                {
                    break;
                }

                $position = $start + 1;

                continue;
            }

            $hits = \count(array_filter(
                $expectedIds,
                static fn (string $id): bool => \array_key_exists($id, $candidate),
            ));

            if ($hits >= $bestHits)
            {
                $best = $candidate;
                $bestHits = $hits;
            }

            $position = $end + 1;
        }

        return $best;
    }

    /**
     * The offset of the brace that closes the object opened at $start, or null.
     */
    private function balancedEnd(string $raw, int $start, int $length): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; ++$i)
        {
            $char = $raw[$i];

            if ($inString)
            {
                if ($escaped)
                {
                    $escaped = false;
                } elseif ('\\' === $char)
                {
                    $escaped = true;
                } elseif ('"' === $char)
                {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char)
            {
                $inString = true;
            } elseif ('{' === $char)
            {
                ++$depth;
            } elseif ('}' === $char && 0 === --$depth)
            {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null null unless the text is a JSON object
     */
    private function decodeObject(string $json): ?array
    {
        if (!json_validate($json))
        {
            return null;
        }

        $decoded = json_decode($json, true);

        // An empty object is a well-formed answer that says nothing, which is
        // reported as every feature missing rather than as a parse failure.
        if (!\is_array($decoded) || ([] !== $decoded && array_is_list($decoded)))
        {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Returns the reading (null when nothing in the answer matched the menu)
     * and each off-menu item with the menu value it became ('' when it became
     * nothing).
     *
     * @return array{0: Reading|null, 1: list<array{0: string, 1: string}>}
     */
    private function toReading(Feature $feature, mixed $answer): array
    {
        $menu = Menu::for($feature);

        if ($feature->isMultiSelect())
        {
            // A list is the expected shape and a bare string a forgivable one.
            // Anything else (an object, a number, true) is one garbled answer.
            if (\is_array($answer) && !array_is_list($answer))
            {
                return [null, [[self::stringify($answer), '']]];
            }

            $given = \is_array($answer) ? $answer : [$answer];
            $repairs = [];
            $values = [];
            $firstSnappedFrom = null;

            // Items that are not strings cannot be on the menu. They are
            // reported, never dropped, so an answer made only of them reads as
            // missing rather than as an empty selection.
            foreach ($given as $item)
            {
                if (!\is_string($item))
                {
                    $repairs[] = [self::stringify($item), ''];
                }
            }

            foreach ($menu->snapEach($given) as $item)
            {
                if (null !== $item['value'] && !\in_array($item['value'], $values, true))
                {
                    $values[] = $item['value'];
                }

                if ($item['repaired'])
                {
                    $repairs[] = [$item['given'], $item['value'] ?? ''];
                    $firstSnappedFrom ??= $item['given'];
                }
            }

            // Items were offered and none of them was on the menu: that is an
            // unreadable answer, not an empty selection.
            if ([] === $values && [] !== $repairs)
            {
                return [null, $repairs];
            }

            return [new Reading(
                featureId: $feature->id,
                value: $values,
                confidence: $this->config->readingConfidence,
                note: [] === $values ? 'the model returned an empty selection' : '',
                source: Reading::SOURCE_LLM,
                snappedFrom: $firstSnappedFrom,
            ), $repairs];
        }

        $given = \is_string($answer) ? $answer : self::stringify($answer);
        [$value, $snappedFrom] = $menu->snap($given);

        if (null === $value)
        {
            return [null, [[$given, '']]];
        }

        return [new Reading(
            featureId: $feature->id,
            value: $value,
            confidence: $this->config->readingConfidence,
            note: '',
            source: Reading::SOURCE_LLM,
            snappedFrom: $snappedFrom,
        ), null === $snappedFrom ? [] : [[$given, $value]]];
    }

    private static function stringify(mixed $value): string
    {
        if (\is_bool($value))
        {
            return $value ? 'true' : 'false';
        }

        if (\is_scalar($value))
        {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return false === $encoded ? '' : $encoded;
    }
}
