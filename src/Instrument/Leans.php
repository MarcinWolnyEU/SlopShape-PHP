<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

/**
 * Which class a calibrated value is more common in.
 *
 * `Ai` values push the score up, `Human` values pull it down. The direction is
 * a released property of the value, not something this package decides.
 */
enum Leans: string {
    case Ai = 'ai';
    case Human = 'human';

    /**
     * The sign this value applies to the log-odds sum.
     */
    public function sign(): int
    {
        return match ($this)
        {
            self::Ai => 1,
            self::Human => -1,
        };
    }

    public function isAi(): bool
    {
        return self::Ai === $this;
    }
}
