<?php

declare(strict_types=1);

namespace SlopShape\Scoring;

use InvalidArgumentException;
use SlopShape\Instrument\CoreValue;
use SlopShape\Instrument\Feature;

/**
 * The released scoring model, in four lines of arithmetic.
 *
 * The study released calibration at value granularity, and for the ten core
 * values that calibration is a signed direction plus a prevalence gap. So the
 * score is a transparent log-odds sum:
 *
 *     weight(gap)      = calibration * ln((1 + gap) / (1 - gap))
 *     contribution     = confidence * weight * direction * signal
 *     P[AI]            = sigmoid(sum of contributions)
 *
 * This is not the study's classifier, which is XGBoost over 176 structurally
 * scored features at 0.9695 macro-F1. It is a log-odds sum over ten of those
 * features' values, weighted by the gaps the study published. Read it as an
 * evidence summary, not a measurement.
 */
final readonly class LogOdds {
    /** Exponential overflows somewhere past this; clamping is standard practice. */
    private const float SIGMOID_CLAMP = 30.0;

    /**
     * @param float $calibration scales every weight; see {@see \SlopShape\Config::$calibration}
     */
    public function __construct(private float $calibration)
    {
        if (!is_finite($calibration))
        {
            throw new InvalidArgumentException('The calibration constant must be a finite number.');
        }
    }

    public function calibration(): float
    {
        return $this->calibration;
    }

    /**
     * The log-odds weight of a value with this prevalence gap.
     *
     * ln((1+g)/(1-g)) is the log-odds of a proportion, so a value that is twice
     * as common in one class weighs roughly twice as heavily. Gaps are clamped
     * below 0.95 because the expression diverges at 1.
     */
    public function weight(float $gap): float
    {
        $gap = is_nan($gap) ? 0.0 : min(max($gap, 0.0), 0.95);

        return $this->calibration * log((1.0 + $gap) / (1.0 - $gap));
    }

    /**
     * How strongly a binary marker fired: 1 when present, 0 when not.
     */
    public function markerSignal(bool $present): float
    {
        return $present ? 1.0 : 0.0;
    }

    /**
     * How far an ordinal reading sits from parity, signed towards the earliest
     * value.
     *
     * Ordinal columns are rescaled to [0, 1] by max index and measured on that
     * scale, so the signal is (parity - observed) over the half-range: +1 at the
     * earliest value, -1 at the latest, 0 at the midpoint. Capped at +/-2 so
     * that no single ordinal can dominate a sum of ten.
     */
    public function ordinalSignal(Feature $feature, ?string $value): float
    {
        if (null === $value)
        {
            return 0.0;
        }

        $index = array_search($value, $feature->values, true);

        if (false === $index)
        {
            return 0.0;
        }

        $scale = max(\count($feature->values) - 1, 1);
        $normalised = $index / $scale;          // 0..1, as the study encodes it
        $deviation = 0.5 - $normalised;          // > 0 = earlier than parity

        return max(-2.0, min(2.0, $deviation / 0.5));
    }

    /**
     * The signed log-odds one core value contributes.
     *
     * For an ordinal value the sign flips relative to the other kinds, because
     * `leans` names the class holding the *higher* end of the rescaled scale,
     * so evidence on the low side of parity is the other class, by definition.
     *
     * @param float $signal from {@see self::markerSignal()} or {@see self::ordinalSignal()}
     * @param float $confidence how much the reading is trusted, 0..1
     */
    public function contribution(CoreValue $coreValue, float $signal, float $confidence): float
    {
        $weight = $this->weight($coreValue->gap);

        if ($coreValue->ordinal)
        {
            return -$coreValue->leans->sign() * $signal * $weight * $confidence;
        }

        return $coreValue->leans->sign() * $signal * $weight * $confidence;
    }

    /**
     * Turn a log-odds sum into a probability.
     *
     * NaN carries no evidence, so it maps to 0.5 rather than being clamped to
     * an extreme by the comparison below.
     */
    public static function sigmoid(float $logOdds): float
    {
        if (is_nan($logOdds))
        {
            return 0.5;
        }

        $logOdds = max(-self::SIGMOID_CLAMP, min(self::SIGMOID_CLAMP, $logOdds));

        return 1.0 / (1.0 + exp(-$logOdds));
    }
}
