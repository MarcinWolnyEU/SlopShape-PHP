<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Regenerate resources/instrument.json from a SlopShape checkout
|--------------------------------------------------------------------------
|
|   php tools/build-instrument.php /path/to/slopshape [output.json]
|
| Everything the study released is read from the checkout and hashed into the
| provenance block: the core values and their gaps, the feature definitions and
| answer menus, the exclusion records, and the corpus word counts. The values
| below that are not read from the release are this package's own choices, and
| each says so.
|
| The output is written only after every core value has been matched to a
| surviving feature and a menu value, so a release that moved in an unexpected
| way stops the build instead of producing a quietly different instrument.
*/

const SOURCES = [
    'features_and_menus' => 'instrument/condensed_taxonomy_0.85.json',
    'value_calibration' => 'artifacts/r6/core_values_selection.json',
    'floor_exclusions_applied' => 'instrument/feature_exclusions.json',
    'format_exclusions_applied' => 'instrument/format_exclusions.json',
    'length_statistics' => 'fetch/corpus_manifest.csv',
];

// Matches aishape's DIMENSION_LABELS, which follows PAPER_TABLES.md naming.
const DIMENSION_LABELS = [
    'purpose_reader_payoff' => 'Purpose',
    'audience_problem_stakes' => 'Audience',
    'structure_and_flow' => 'Structure',
    'explanation_depth' => 'Explanation',
    'evidence_and_proof' => 'Evidence',
    'voices_and_sources' => 'Voices',
    'actionability' => 'Actionability',
    'brand_product_integration' => 'Commercial integration',
    'timeliness' => 'Timeliness',
    'page_format_navigation' => 'Page format',
    'writing_style' => 'Writing style',
];

// Countable, so the tool measures it instead of asking a model (as aishape).
const MEASURED_BY_TOOL = ['PAG_FUR_011'];

function fail(string $message): never
{
    fwrite(\STDERR, 'build-instrument: ' . $message . \PHP_EOL);
    exit(1);
}

/**
 * @return array<array-key, mixed>
 */
function readJson(string $path): array
{
    $raw = @file_get_contents($path);

    if (false === $raw)
    {
        fail("cannot read {$path}");
    }

    $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    if (!is_array($data))
    {
        fail("{$path} is not a JSON object");
    }

    return $data;
}

/**
 * Nearest-rank percentile over sorted values, so every statistic is a word
 * count some document in the corpus actually has.
 *
 * @param list<int> $sorted
 */
function percentile(array $sorted, int $q): int
{
    return $sorted[max(0, (int) ceil($q / 100 * count($sorted)) - 1)];
}

$checkout = rtrim($argv[1] ?? '', '/\\');
$output = $argv[2] ?? dirname(__DIR__) . '/resources/instrument.json';

if ('' === $checkout || !is_dir($checkout))
{
    fail('usage: php tools/build-instrument.php /path/to/slopshape [output.json]');
}

$sha256 = [];

foreach (SOURCES as $relative)
{
    $hash = @hash_file('sha256', "{$checkout}/{$relative}");

    if (false === $hash)
    {
        fail("missing release file {$relative}");
    }

    $sha256[$relative] = $hash;
}

// Feature definitions, with the dimension and aspect they are filed under.
$definitions = [];

foreach (readJson("{$checkout}/" . SOURCES['features_and_menus'])['feature_taxonomy'] ?? [] as $dimension => $block)
{
    foreach ($block['aspects'] ?? [] as $aspect => $aspectBlock)
    {
        foreach ($aspectBlock['features'] ?? [] as $feature)
        {
            $definitions[$feature['id']] = [$feature, $dimension, $aspect];
        }
    }
}

$excluded = [];

foreach (array_keys(readJson("{$checkout}/" . SOURCES['floor_exclusions_applied'])['exclusions'] ?? []) as $id)
{
    $excluded[$id] = 'floor';
}

foreach (readJson("{$checkout}/" . SOURCES['format_exclusions_applied'])['excluded'] ?? [] as $id)
{
    $excluded[$id] = 'format';
}

// Core values, largest gap first.
$selection = readJson("{$checkout}/" . SOURCES['value_calibration']);
$coreValues = [];
$coreFeatureIds = [];

foreach ($selection['core_values'] ?? [] as $column)
{
    [$featureId, $value] = explode('__', $column, 2) + [1 => ''];

    if (!isset($definitions[$featureId]))
    {
        fail("core value {$column} names a feature that is not in the taxonomy");
    }

    if (isset($excluded[$featureId]))
    {
        fail("core value {$column} names a feature the release excluded ({$excluded[$featureId]})");
    }

    $ordinal = 'ord' === $value;

    if (!$ordinal && !in_array($value, $definitions[$featureId][0]['values'], true))
    {
        fail("core value {$column}: \"{$value}\" is not on the {$featureId} menu");
    }

    $leans = $selection['value_signs'][$column] ?? null;
    $gap = $selection['value_gaps'][$column] ?? null;

    if (!in_array($leans, ['ai', 'human'], true) || !is_numeric($gap))
    {
        fail("core value {$column} has no sign or gap");
    }

    $coreValues[] = [
        'column' => $column,
        'feature_id' => $featureId,
        'value' => $value,
        'leans' => $leans,
        'gap' => (float) $gap,
        'ordinal' => $ordinal,
    ];
    $coreFeatureIds[$featureId] = true;
}

if (count($coreValues) !== ($selection['n_core_values'] ?? -1)
    || count($coreFeatureIds) !== ($selection['n_core_features'] ?? -1))
{
    fail('core value counts do not match n_core_values / n_core_features');
}

usort($coreValues, static fn (array $a, array $b): int => [$b['gap'], $a['column']] <=> [$a['gap'], $b['column']]);

// Measured features first, then by id.
$ids = array_keys($coreFeatureIds);
usort($ids, static fn (string $a, string $b): int => [!in_array($a, MEASURED_BY_TOOL, true), $a] <=> [!in_array($b, MEASURED_BY_TOOL, true), $b]);

$features = [];

foreach ($ids as $id)
{
    [$feature, $dimension, $aspect] = $definitions[$id];
    $features[] = [
        'id' => $id,
        'name' => $feature['name'],
        'question' => $feature['question'],
        'type' => $feature['type'],
        'values' => $feature['values'],
        'detection_method' => $feature['detection_method'],
        'dimension' => $dimension,
        'dimension_label' => DIMENSION_LABELS[$dimension] ?? $dimension,
        'aspect' => $aspect,
        'measured_by_tool' => in_array($id, MEASURED_BY_TOOL, true),
    ];
}

// Corpus word counts.
$handle = fopen("{$checkout}/" . SOURCES['length_statistics'], 'r');

if (false === $handle)
{
    fail('cannot open the corpus manifest');
}

$header = fgetcsv($handle, escape: '');
$wordsAt = is_array($header) ? array_search('words', $header, true) : false;

if (false === $wordsAt)
{
    fail('the corpus manifest has no "words" column');
}

$words = [];

while (is_array($row = fgetcsv($handle, escape: '')))
{
    if (isset($row[$wordsAt]) && ctype_digit($row[$wordsAt]))
    {
        $words[] = (int) $row[$wordsAt];
    }
}

fclose($handle);
sort($words);

if ([] === $words)
{
    fail('the corpus manifest has no word counts');
}

$under800 = count(array_filter($words, static fn (int $n): bool => $n < 800));

$shap = $selection['core_attribution_share'] ?? [];
$metrics = $selection['variants']['core_only']['test'] ?? null;

if (!is_array($metrics) || !isset($shap['core_mean_abs_shap_sum']))
{
    fail('core_values_selection.json has no core-only test metrics or attribution share');
}

$anchor = $shap['core_mean_abs_shap_sum'] / count($coreFeatureIds);
$floorCount = count(array_filter($excluded, static fn (string $kind): bool => 'floor' === $kind));
$formatCount = count($excluded) - $floorCount;

$instrument = [
    'schema_version' => 1,
    'provenance' => [
        'study' => 'SlopShape: Identifying AI-Generated Commercial Web Content',
        'study_author' => 'Jochen Madler (Sitefire)',
        'study_year' => 2026,
        'study_identifier' => 'arXiv:2609.15369',
        'study_url' => 'https://arxiv.org/abs/2609.15369',
        'instrument_version' => sprintf(
            'condensed taxonomy 0.85, minus the %d outcome-blind floor exclusions and the %d format-sensitivity exclusions',
            $floorCount,
            $formatCount,
        ),
        'derived_from' => SOURCES,
        'sha256' => $sha256,
        'licence' => 'The instrument, calibration and statistics in this file are derived from the SlopShape release package, which is (c) the authors, all rights reserved, and published for verification and audit.',
        'core_only_metrics' => [
            'macro_f1' => $metrics['macro_f1'],
            'auprc' => $metrics['auprc'],
            'accuracy' => $metrics['accuracy'],
        ],
    ],
    'calibration' => [
        // Local choices, not release numbers; see README "Configuration".
        'default' => 0.6,
        'ai_verdict_at' => 0.8,
        'human_verdict_at' => 0.2,
        'min_core_values' => 4,
        'min_latin_ratio' => 0.8,
        'read_floor_words' => 250,
        // The shortest document the study scored.
        'judgement_floor_words' => $words[0],
        'corpus_length' => [
            'n' => count($words),
            'min' => $words[0],
            'p10' => percentile($words, 10),
            'median' => percentile($words, 50),
            'p90' => percentile($words, 90),
            'max' => $words[count($words) - 1],
            'share_under_800' => round($under800 / count($words), 3),
        ],
        'note' => sprintf(
            'weight(gap) = calibration * ln((1 + gap) / (1 - gap)); the default 0.6 is a local choice anchored to the study\'s mean per-core-feature |SHAP| (%s / %d = %.2f log-odds), not a number from the paper.',
            $shap['core_mean_abs_shap_sum'],
            count($coreFeatureIds),
            $anchor,
        ),
    ],
    'features' => $features,
    'core_values' => $coreValues,
];

$json = json_encode($instrument, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
// Two-space indentation, as the file has always had.
$json = preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat('  ', strlen($m[0]) / 4), $json);

if (false === file_put_contents($output, $json . "\n"))
{
    fail("cannot write {$output}");
}

fwrite(\STDOUT, sprintf("wrote %s: %d features, %d core values\n", $output, count($features), count($coreValues)));
