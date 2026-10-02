<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

/**
 * Where this instrument came from, and under what terms.
 *
 * The instrument is study data, not this package's invention. A probability
 * that cannot be traced to a paper, a release artefact and a licence is not
 * evidence, so the provenance travels with the instrument and is available from
 * every {@see \SlopShape\Score}.
 */
final readonly class Provenance {
    /**
     * @param array<string, string> $derivedFrom release-relative file -> what was taken from it
     * @param array<string, string> $sha256 release-relative file -> content hash
     * @param array{macro_f1?: float, auprc?: float, accuracy?: float} $coreOnlyMetrics
     *                                                                                  the study's reported test metrics for the ten core values alone
     */
    public function __construct(
        public string $study,
        public string $studyAuthor,
        public int $studyYear,
        public string $studyIdentifier,
        public string $studyUrl,
        public string $instrumentVersion,
        public array $derivedFrom = [],
        public array $sha256 = [],
        public string $licence = '',
        public array $coreOnlyMetrics = [],
    ) {
    }

    /**
     * @return array{
     *     study: string,
     *     study_author: string,
     *     study_year: int,
     *     study_identifier: string,
     *     study_url: string,
     *     instrument_version: string,
     *     derived_from: array<string, string>,
     *     sha256: array<string, string>,
     *     licence: string,
     *     core_only_metrics: array{macro_f1?: float, auprc?: float, accuracy?: float},
     * }
     */
    public function toArray(): array
    {
        return [
            'study' => $this->study,
            'study_author' => $this->studyAuthor,
            'study_year' => $this->studyYear,
            'study_identifier' => $this->studyIdentifier,
            'study_url' => $this->studyUrl,
            'instrument_version' => $this->instrumentVersion,
            'derived_from' => $this->derivedFrom,
            'sha256' => $this->sha256,
            'licence' => $this->licence,
            'core_only_metrics' => $this->coreOnlyMetrics,
        ];
    }
}
