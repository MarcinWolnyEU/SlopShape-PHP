<?php

declare(strict_types=1);

namespace SlopShape;

use Closure;
use SlopShape\Bridge\LlmBridge;
use SlopShape\Instrument\Instrument;

/**
 * Score a text in one call. The function form of {@see Detector}.
 *
 * ```php
 * use function SlopShape\detect;
 *
 * $score = detect($text, static fn (string $p): string => $client->chat($p));
 * ```
 *
 * Convenient for scripts, tests and one-off calls. In application code, build a
 * {@see Detector} once and reuse it: it loads the instrument once and holds no
 * per-document state.
 *
 * @param LlmBridge|(Closure(string): string)|null $bridge your model, in and out;
 *                                                         null to score only what the package measures itself
 *
 * @throws Exception\EmptyText when there is nothing to measure
 * @throws Exception\BridgeFailure when the bridge throws
 * @throws Exception\UnreadableResponse when the answer is not the JSON object asked for
 */
function detect(
    string $text,
    LlmBridge|Closure|null $bridge = null,
    ?Config $config = null,
    ?Instrument $instrument = null,
): Score {
    return (new Detector($bridge, $config, $instrument))->detect($text);
}

/**
 * The probability alone, as a float in [0, 1].
 *
 * Use it to sort, filter or threshold. Use {@see detect()} for anything a human
 * is going to read, because a bare probability with no evidence behind it is
 * the shape of a lawsuit.
 *
 * @param LlmBridge|(Closure(string): string)|null $bridge your model, in and out;
 *                                                         null to score only what the package measures itself
 */
function probabilityAi(
    string $text,
    LlmBridge|Closure|null $bridge = null,
    ?Config $config = null,
    ?Instrument $instrument = null,
): float {
    return (new Detector($bridge, $config, $instrument))->probabilityAi($text);
}
