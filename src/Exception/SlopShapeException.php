<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use Throwable;

/**
 * Marker for every exception this package throws.
 *
 * Catch this to handle anything originating in SlopShape-PHP without also swallowing
 * transport or application errors from your own code.
 */
interface SlopShapeException extends Throwable {
}
