<?php

declare(strict_types=1);

namespace SlopShape;

use Closure;
use SlopShape\Bridge\ClosureBridge;
use SlopShape\Bridge\LlmBridge;
use SlopShape\Exception\BridgeFailure;
use SlopShape\Exception\EmptyText;
use SlopShape\Exception\UnreadableResponse;
use SlopShape\Instrument\Instrument;
use SlopShape\Reading\LlmReader;
use SlopShape\Reading\ReadBatch;
use SlopShape\Reading\Reading;
use SlopShape\Reading\WordCountReader;
use SlopShape\Scoring\Scorer;
use SlopShape\Text\Document;
use Throwable;

/**
 * Scores text for AI slop.
 *
 * ```php
 * use SlopShape\Detector;
 *
 * $detector = new Detector(
 *     static fn (string $prompt): string => $client->chat($prompt),
 * );
 *
 * $score = $detector->detect($text);
 *
 * $score->probabilityAi;     // 0.89
 * $score->confidence;        // 0.71
 * $score->positiveFactors(); // evidence for AI, strongest first
 * $score->negativeFactors(); // evidence for human, strongest first
 * ```
 *
 * How the answer is produced, in one sentence: the package measures what it can
 * measure itself, asks your model about the rest using the study's own
 * annotation prompt and frozen answer menus, and turns the answers into
 * log-odds using the value-level calibration the study published.
 *
 * There is no timeout here. The bridge owns timeouts, retries and budgets: a
 * bridge that hangs makes {@see self::detect()} hang, so set them on your client.
 *
 * The detector is immutable. Build one, reuse it; it holds no per-document
 * state and the instrument it reads is loaded once.
 */
final readonly class Detector {
    private ?LlmBridge $bridge;

    private Instrument $instrument;

    private Config $config;

    /**
     * @param LlmBridge|(Closure(string): string)|null $bridge your model, in and out;
     *                                                         null to score only what the package measures itself
     * @param Config|null $config null for the released defaults
     * @param Instrument|null $instrument null for the vendored instrument
     */
    public function __construct(
        LlmBridge|Closure|null $bridge = null,
        ?Config $config = null,
        ?Instrument $instrument = null,
    ) {
        $this->bridge = $bridge instanceof Closure ? new ClosureBridge($bridge) : $bridge;
        $this->config = $config ?? new Config();
        $this->instrument = $instrument ?? Instrument::bundled();
    }

    /**
     * Score a document.
     *
     * @throws EmptyText when there is nothing left to measure after normalisation
     * @throws BridgeFailure when the bridge throws
     * @throws UnreadableResponse when the bridge answers with something that is not the
     *                            JSON object the annotation prompt asked for
     */
    public function detect(string $text): Score
    {
        $document = Document::fromString($text);

        // "Nothing to read" is judged over any script, not just Latin: a
        // Japanese document is readable but outside the instrument's
        // calibrated range, and that is the answer it deserves.
        if (0 === $document->allWordCount)
        {
            throw EmptyText::forLength(mb_strlen($document->text));
        }

        $scorer = new Scorer($this->instrument, $this->config);

        return $scorer->score($document, $this->readingSet($document));
    }

    /**
     * Just the probability, for callers that only need to sort or filter.
     */
    public function probabilityAi(string $text): float
    {
        return $this->detect($text)->probabilityAi;
    }

    /**
     * Reuse this detector with different settings, sharing the instrument it
     * has already loaded.
     */
    public function withConfig(Config $config): self
    {
        return new self($this->bridge, $config, $this->instrument);
    }

    public function withBridge(LlmBridge|Closure|null $bridge): self
    {
        return new self($bridge, $this->config, $this->instrument);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function instrument(): Instrument
    {
        return $this->instrument;
    }

    public function bridge(): ?LlmBridge
    {
        return $this->bridge;
    }

    /**
     * The readings behind a score, for a report that needs to show its work.
     *
     * This makes its own call to the bridge, so keep it out of scoring paths.
     * Without a bridge only the package's own measurements are returned.
     */
    public function readings(Document $document): ReadBatch
    {
        $tool = [];

        foreach ((new WordCountReader())->read($this->instrument, $document) as $reading)
        {
            $tool[$reading->featureId] = $reading;
        }

        $measured = ReadBatch::fromReadings($tool);

        return $this->bridge instanceof LlmBridge ? $this->ask($this->bridge, $document)->merge($measured) : $measured;
    }

    /**
     * @return array<string, Reading>
     */
    private function readingSet(Document $document): array
    {
        $readings = [];

        foreach ($this->readings($document)->readings() as $id => $reading)
        {
            $readings[$id] = $reading;
        }

        return $readings;
    }

    /**
     * Only the bridge call is translated into a {@see BridgeFailure}. A fault in
     * this package's own parsing or scoring is a bug, and it propagates as
     * itself instead of being blamed on the caller's model.
     */
    private function ask(LlmBridge $bridge, Document $document): ReadBatch
    {
        $guarded = new readonly class($bridge, $this->bridgeName($bridge)) implements LlmBridge {
            public function __construct(private LlmBridge $bridge, private string $name)
            {
            }

            public function complete(string $prompt): string
            {
                try
                {
                    return $this->bridge->complete($prompt);
                } catch (BridgeFailure $failure)
                {
                    throw $failure;
                } catch (Throwable $exception)
                {
                    throw BridgeFailure::from($exception, $this->name);
                }
            }
        };

        return (new LlmReader($guarded, $this->config))->read($this->instrument, $document);
    }

    private function bridgeName(LlmBridge $bridge): string
    {
        return $bridge instanceof ClosureBridge ? $bridge->name : 'the LLM bridge';
    }
}
