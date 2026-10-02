<?php

declare(strict_types=1);

namespace SlopShape;

use InvalidArgumentException;
use SlopShape\Instrument\Calibration;

/**
 * Settings for the detector.
 * Typically you should not touch this. This is exposed only for advanced fine-tuning.
 *
 * Only {@see self::$judgementFloorWords} is a number from the study's data (the
 * shortest document in its corpus). Every other default is this package's own
 * choice, not a number from the paper. Those that live in the instrument's
 * {@see Calibration} block are asserted against the vendored instrument by a
 * test; {@see self::$readingConfidence} and {@see self::$maxInputChars} are not
 * in it. The choice with the most effect is {@see self::$calibration}: it scales
 * every weight, so raising it makes the score more decisive and lowering it
 * makes it gentler.
 *
 * Named arguments over fluent setters, because a config that can be built in
 * one expression and then never mutates is a config you can cache, serialise and
 * log.
 */
final readonly class Config {
    public function __construct(
        /** Scales every weight. Not a number from the paper; see the class docblock. */
        public float $calibration = 0.6,
        /** How far a model's reading is trusted before it is weighted. */
        public float $readingConfidence = 0.9,
        /** P[AI] at or above this is labelled likely AI. */
        public float $aiVerdictAt = 0.80,
        /** P[AI] at or below this is labelled likely human. */
        public float $humanVerdictAt = 0.20,
        /** Fewer readable core values than this, and no label is issued. */
        public int $minCoreValues = 4,
        /** Below this share of Latin-script words, the instrument is out of range. */
        public float $minLatinRatio = 0.8,
        /** Under this many words, most structural features are unreadable. */
        public int $readFloorWords = 250,
        /** The shortest document the study ever scored; below it, no label. */
        public int $judgementFloorWords = 572,
        /** Hard cap on the text sent to the model. The measurement uses the whole thing. */
        public int $maxInputChars = 24_000,
    ) {
        self::finite('calibration', $calibration);
        self::unit('readingConfidence', $readingConfidence);
        self::unit('aiVerdictAt', $aiVerdictAt);
        self::unit('humanVerdictAt', $humanVerdictAt);
        self::unit('minLatinRatio', $minLatinRatio);

        if ($calibration < 0.0)
        {
            throw new InvalidArgumentException('calibration must not be negative.');
        }

        if ($humanVerdictAt > $aiVerdictAt)
        {
            throw new InvalidArgumentException(\sprintf('humanVerdictAt (%s) must not exceed aiVerdictAt (%s).', $humanVerdictAt, $aiVerdictAt));
        }

        if ($minCoreValues < 0)
        {
            throw new InvalidArgumentException('minCoreValues must not be negative.');
        }

        if ($readFloorWords < 0 || $judgementFloorWords < 0)
        {
            throw new InvalidArgumentException('Word floors must not be negative.');
        }

        if ($readFloorWords > $judgementFloorWords)
        {
            throw new InvalidArgumentException(\sprintf('readFloorWords (%d) must not exceed judgementFloorWords (%d).', $readFloorWords, $judgementFloorWords));
        }

        if ($maxInputChars < 1)
        {
            throw new InvalidArgumentException('maxInputChars must be a positive integer.');
        }
    }

    public function withCalibration(float $calibration): self
    {
        return $this->with(calibration: $calibration);
    }

    public function withReadingConfidence(float $readingConfidence): self
    {
        return $this->with(readingConfidence: $readingConfidence);
    }

    public function withMaxInputChars(int $maxInputChars): self
    {
        return $this->with(maxInputChars: $maxInputChars);
    }

    /**
     * Defaults taken from an instrument's own calibration block, for callers who
     * load the release artifacts rather than the vendored copy.
     */
    public static function fromCalibration(Calibration $calibration): self
    {
        return new self(
            calibration: $calibration->default,
            aiVerdictAt: $calibration->aiVerdictAt,
            humanVerdictAt: $calibration->humanVerdictAt,
            minCoreValues: $calibration->minCoreValues,
            minLatinRatio: $calibration->minLatinRatio,
            readFloorWords: $calibration->readFloorWords,
            judgementFloorWords: $calibration->judgementFloorWords,
        );
    }

    private static function finite(string $name, float $value): void
    {
        if (!is_finite($value))
        {
            throw new InvalidArgumentException(\sprintf('%s must be a finite number.', $name));
        }
    }

    private static function unit(string $name, float $value): void
    {
        if (!is_finite($value) || $value < 0.0 || $value > 1.0)
        {
            throw new InvalidArgumentException(\sprintf('%s must be a number between 0 and 1.', $name));
        }
    }

    private function with(
        ?float $calibration = null,
        ?float $readingConfidence = null,
        ?int $maxInputChars = null,
    ): self {
        return new self(
            calibration: $calibration ?? $this->calibration,
            readingConfidence: $readingConfidence ?? $this->readingConfidence,
            aiVerdictAt: $this->aiVerdictAt,
            humanVerdictAt: $this->humanVerdictAt,
            minCoreValues: $this->minCoreValues,
            minLatinRatio: $this->minLatinRatio,
            readFloorWords: $this->readFloorWords,
            judgementFloorWords: $this->judgementFloorWords,
            maxInputChars: $maxInputChars ?? $this->maxInputChars,
        );
    }
}
