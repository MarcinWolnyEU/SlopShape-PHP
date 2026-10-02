<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

use JsonException;
use SlopShape\Exception\InstrumentUnavailable;
use SlopShape\Exception\UnknownFeature;
use ValueError;

/**
 * The frozen ten-value core of the SlopShape instrument, with its released
 * calibration.
 *
 * The study froze 214 features and released calibration at value granularity
 * for exactly ten of them. Those ten are the instrument: everything else is
 * deliberately not here, because a feature with no released weight has no
 * business inside a probability.
 *
 * Instances are immutable and cheap to share. {@see self::bundled()} reads the
 * copy vendored with this package; {@see self::fromFile()} loads one from a
 * SlopShape checkout instead, which is the path to take if the terms of the
 * release data do not suit your distribution.
 */
final readonly class Instrument {
    public const int SCHEMA_VERSION = 1;

    /**
     * @param array<string, Feature> $features
     * @param list<CoreValue> $coreValues ordered by released gap, largest first
     * @param list<Feature> $promptFeatures measured features last, in id order
     */
    private function __construct(
        private array $features,
        private array $coreValues,
        private array $promptFeatures,
        public Calibration $calibration,
        public Provenance $provenance,
    ) {
    }

    /**
     * The instrument vendored with this package.
     */
    public static function bundled(): self
    {
        return self::fromFile(\dirname(__DIR__, 2) . '/resources/instrument.json');
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if (false === $raw)
        {
            throw InstrumentUnavailable::unreadable($path);
        }

        return self::fromJson($raw, $path);
    }

    public static function fromJson(string $json, string $origin = '<json>'): self
    {
        try
        {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException)
        {
            throw InstrumentUnavailable::notJson($origin);
        }

        if (!\is_array($decoded))
        {
            throw InstrumentUnavailable::malformed($origin, ['the top level is not an object']);
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded, $origin);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $origin = '<array>'): self
    {
        $features = self::readFeatures($data, $origin);
        $coreValues = self::readCoreValues($data, $origin, $features);

        $promptFeatures = array_values(array_filter(
            $features,
            static fn (Feature $feature): bool => !$feature->measuredByTool,
        ));
        usort(
            $promptFeatures,
            static fn (Feature $a, Feature $b): int => $a->id <=> $b->id,
        );

        return new self(
            $features,
            $coreValues,
            $promptFeatures,
            self::readCalibration($data, $origin),
            self::readProvenance($data),
        );
    }

    /**
     * @return list<CoreValue>
     */
    public function coreValues(): array
    {
        return $this->coreValues;
    }

    /**
     * The features a model is asked about, in prompt order.
     *
     * Anything the tool can measure exactly is absent here: word count is
     * countable, so asking a model about it is strictly worse.
     *
     * @return list<Feature>
     */
    public function promptFeatures(): array
    {
        return $this->promptFeatures;
    }

    /**
     * The features the tool measures itself.
     *
     * @return list<Feature>
     */
    public function measuredFeatures(): array
    {
        return array_values(array_filter(
            $this->features,
            static fn (Feature $feature): bool => $feature->measuredByTool,
        ));
    }

    public function feature(string $id): Feature
    {
        return $this->features[$id]
            ?? throw UnknownFeature::forId($id, $this->ids());
    }

    public function has(string $id): bool
    {
        return isset($this->features[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->features);
    }

    public function count(): int
    {
        return \count($this->features);
    }

    /**
     * The ids the prompt is expected to answer, in the order asked.
     *
     * @return list<string>
     */
    public function promptFeatureIds(): array
    {
        return array_map(
            static fn (Feature $feature): string => $feature->id,
            $this->promptFeatures,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, Feature>
     */
    private static function readFeatures(array $data, string $origin): array
    {
        $rows = $data['features'] ?? null;

        if (!\is_array($rows) || [] === $rows)
        {
            throw InstrumentUnavailable::malformed($origin, ['no "features" array']);
        }

        $features = [];

        foreach ($rows as $index => $row)
        {
            if (!\is_array($row))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('features[%s] is not an object', (string) $index)]);
            }

            $id = self::str($row, 'id');

            if (null === $id)
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('features[%s] has no id', (string) $index)]);
            }

            if (isset($features[$id]))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('features[%s] repeats the feature id "%s"', (string) $index, $id)]);
            }

            $values = [];

            /** @var mixed $value */
            foreach ((\is_array($row['values'] ?? null) ? $row['values'] : []) as $value)
            {
                if (\is_string($value) && '' !== $value)
                {
                    $values[] = $value;
                }
            }

            if ([] === $values)
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('feature "%s" has no values', $id)]);
            }

            $type = self::str($row, 'type') ?? 'categorical';

            try
            {
                $features[$id] = new Feature(
                    id: $id,
                    name: self::req($row, 'name', $id),
                    question: self::str($row, 'question') ?? '',
                    type: FeatureType::fromTaxonomy($type),
                    values: $values,
                    detectionMethod: self::str($row, 'detection_method') ?? '',
                    dimension: self::str($row, 'dimension') ?? '',
                    dimensionLabel: self::str($row, 'dimension_label') ?? '',
                    aspect: self::str($row, 'aspect') ?? '',
                    measuredByTool: ($row['measured_by_tool'] ?? false) === true,
                );
            } catch (ValueError $exception)
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('feature "%s": %s', $id, $exception->getMessage())]);
            }
        }

        return $features;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, Feature> $features
     *
     * @return list<CoreValue>
     */
    private static function readCoreValues(array $data, string $origin, array $features): array
    {
        $rows = $data['core_values'] ?? null;

        if (!\is_array($rows) || [] === $rows)
        {
            throw InstrumentUnavailable::malformed($origin, ['no "core_values" array']);
        }

        $coreValues = [];
        $seen = [];

        foreach ($rows as $index => $row)
        {
            if (!\is_array($row))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s] is not an object', (string) $index)]);
            }

            $featureId = self::str($row, 'feature_id') ?? '';
            $value = self::str($row, 'value') ?? '';
            $ordinal = ($row['ordinal'] ?? false) === true;

            if ('' === $featureId || '' === $value)
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s] has no feature id or value', (string) $index)]);
            }

            if (!isset($features[$featureId]))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s] refers to unknown feature "%s"', (string) $index, $featureId)]);
            }

            // An ordinal core value calibrates the whole scale, not one rung of
            // it, so its "value" is the column marker ("ord") rather than an
            // allowed answer. Membership is checked for everything else.
            if (!$ordinal && !$features[$featureId]->hasValue($value))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s]: "%s" is not an allowed value of %s', (string) $index, $value, $featureId)]);
            }

            $key = $featureId . "\0" . $value;

            if (isset($seen[$key]))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s] repeats "%s" of %s', (string) $index, $value, $featureId)]);
            }

            $seen[$key] = true;

            $gap = \is_int($row['gap'] ?? null) || \is_float($row['gap'] ?? null) ? (float) $row['gap'] : null;

            if (null === $gap)
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s] has a non-numeric gap', (string) $index)]);
            }

            $leans = Leans::tryFrom(self::str($row, 'leans') ?? '');

            if (null === $leans)
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('core_values[%s] has an unknown "leans"', (string) $index)]);
            }

            $coreValues[] = new CoreValue(
                column: self::req($row, 'column', \sprintf('%s__%s', $featureId, $value)),
                featureId: $featureId,
                value: $value,
                leans: $leans,
                gap: $gap,
                ordinal: $ordinal,
            );
        }

        usort(
            $coreValues,
            static fn (CoreValue $a, CoreValue $b): int => $b->gap <=> $a->gap,
        );

        return $coreValues;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function readCalibration(array $data, string $origin): Calibration
    {
        $block = \is_array($data['calibration'] ?? null) ? $data['calibration'] : [];
        /** @var array<array-key, mixed> $block */
        $fallback = new Calibration();

        return new Calibration(
            default: self::num($block, 'default', $fallback->default),
            aiVerdictAt: self::num($block, 'ai_verdict_at', $fallback->aiVerdictAt),
            humanVerdictAt: self::num($block, 'human_verdict_at', $fallback->humanVerdictAt),
            minCoreValues: (int) self::num($block, 'min_core_values', (float) $fallback->minCoreValues),
            minLatinRatio: self::num($block, 'min_latin_ratio', $fallback->minLatinRatio),
            readFloorWords: (int) self::num($block, 'read_floor_words', (float) $fallback->readFloorWords),
            judgementFloorWords: (int) self::num($block, 'judgement_floor_words', (float) $fallback->judgementFloorWords),
            corpusLength: self::corpusLength($block, $origin),
            note: self::str($block, 'note') ?? '',
        );
    }

    /**
     * The corpus word-count distribution: absent, or complete and numeric.
     *
     * @param array<array-key, mixed> $block
     *
     * @return array{min: int, p10: int, median: int, p90: int, max: int, n: int, share_under_800: float}|array{}
     */
    private static function corpusLength(array $block, string $origin): array
    {
        $raw = $block['corpus_length'] ?? null;

        if (null === $raw || [] === $raw)
        {
            return [];
        }

        if (!\is_array($raw))
        {
            throw InstrumentUnavailable::malformed($origin, ['calibration.corpus_length is not an object']);
        }

        $out = [];

        foreach (['min', 'p10', 'median', 'p90', 'max', 'n'] as $field)
        {
            $value = $raw[$field] ?? null;

            if (!\is_int($value) && (!\is_float($value) || !is_finite($value)))
            {
                throw InstrumentUnavailable::malformed($origin, [\sprintf('calibration.corpus_length.%s is missing or not a number', $field)]);
            }

            $out[$field] = (int) $value;
        }

        $share = $raw['share_under_800'] ?? null;

        if (!\is_int($share) && (!\is_float($share) || !is_finite($share)))
        {
            throw InstrumentUnavailable::malformed($origin, ['calibration.corpus_length.share_under_800 is missing or not a number']);
        }

        return [...$out, 'share_under_800' => (float) $share];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function readProvenance(array $data): Provenance
    {
        $block = \is_array($data['provenance'] ?? null) ? $data['provenance'] : [];
        /** @var array<array-key, mixed> $block */

        return new Provenance(
            study: self::str($block, 'study') ?? '',
            studyAuthor: self::str($block, 'study_author') ?? '',
            studyYear: (int) self::num($block, 'study_year', 0.0),
            studyIdentifier: self::str($block, 'study_identifier') ?? '',
            studyUrl: self::str($block, 'study_url') ?? '',
            instrumentVersion: self::str($block, 'instrument_version') ?? '',
            derivedFrom: self::strMap($block, 'derived_from'),
            sha256: self::strMap($block, 'sha256'),
            licence: self::str($block, 'licence') ?? '',
            coreOnlyMetrics: self::metrics($block, 'core_only_metrics'),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return non-empty-string|null
     */
    private static function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * A non-empty string, or $default when the field is missing or empty.
     *
     * @param array<array-key, mixed> $row
     *
     * @return non-empty-string
     */
    private static function req(array $row, string $key, string $default = ''): string
    {
        return self::str($row, $key) ?? ('' === $default ? '?' : $default);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function num(array $row, string $key, float $default): float
    {
        $value = $row[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, string>
     */
    private static function strMap(array $row, string $key): array
    {
        $source = \is_array($row[$key] ?? null) ? $row[$key] : [];
        /** @var array<array-key, mixed> $source */
        $out = [];

        /** @var mixed $value */
        foreach ($source as $name => $value)
        {
            if (\is_string($value) && '' !== $value)
            {
                $out[(string) $name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array{macro_f1?: float, auprc?: float, accuracy?: float}
     */
    private static function metrics(array $row, string $key): array
    {
        $source = \is_array($row[$key] ?? null) ? $row[$key] : [];
        /** @var array<array-key, mixed> $source */
        $out = [];

        foreach (['macro_f1', 'auprc', 'accuracy'] as $metric)
        {
            $value = self::num($source, $metric, \PHP_FLOAT_MIN);

            if (\PHP_FLOAT_MIN !== $value)
            {
                $out[$metric] = $value;
            }
        }

        /** @var array{macro_f1?: float, auprc?: float, accuracy?: float} $out */
        return $out;
    }
}
