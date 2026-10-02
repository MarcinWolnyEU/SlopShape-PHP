<?php

declare(strict_types=1);

namespace SlopShape\Bridge;

/**
 * The one seam between this package and a language model.
 *
 * A bridge takes a prompt and returns the model's answer, verbatim. It
 * deliberately has no configuration: temperature, model, transport, retries
 * and token budgets belong to whoever owns the model, because they differ per
 * deployment and this package has no opinion about them.
 *
 * Anything that can do "prompt in, string out" is a bridge: a closure, an
 * OpenAI client, an HTTP call, a local llama binary, a recorded fixture.
 *
 * Contract:
 *  - throw on transport or model failure (the detector wraps it in
 *    {@see \SlopShape\Exception\BridgeFailure});
 *  - return the answer text, unmodified (no trimming, no unwrapping, no
 *    JSON parsing). The detector needs the raw answer to report what happened.
 */
interface LlmBridge {
    /**
     * @param string $prompt a complete instruction, ready to send
     *
     * @return string the model's answer, exactly as received
     */
    public function complete(string $prompt): string;
}
