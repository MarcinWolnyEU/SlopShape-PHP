<?php

declare(strict_types=1);

namespace SlopShape\Tests\Fixtures;

/**
 * Canned model answers and the documents they go with.
 *
 * The answers are what a careful annotator would return for the AI-flavoured
 * fixture: a legacy-versus-modern contrast across the heading sequence,
 * modern-framing freshness cues, a confident institutional voice, mechanism and
 * summary stages, a restated-thesis ending, a route off the page. They exist
 * to pin the arithmetic, and make no attempt to grade the prose.
 */
final class Answers
{
    /**
     * Every core feature a model is asked about, answered on-menu.
     *
     * @return array<string, string|list<string>>
     */
    public static function aiFlavoured(): array
    {
        return [
            'STR_FLW_005' => 'yes',
            'STR_FLW_006' => 'yes',
            'STR_STG_001' => [
                'context',
                'problem_diagnosis',
                'mechanism_explanation',
                'summary_or_synthesis',
                'commercial_cta',
            ],
            'STR_STG_008' => 'restated_thesis_or_reframe',
            'TIM_CUE_003' => ['relative_present_language'],
            'TIM_CUE_011' => ['modern_or_next_generation_framing'],
            'VOC_CRD_001' => 'confident_institutional_explanation',
            'VOC_PRT_005' => 'present',
        ];
    }

    /**
     * A human-shaped document: no legacy contrast, no thesis up front, dated
     * references, no freshness framing, firsthand experience as the credibility
     * basis, a new point at the end, and no route off the page.
     *
     * @return array<string, string|list<string>>
     */
    public static function humanFlavoured(): array
    {
        return [
            'STR_FLW_005' => 'no',
            'STR_FLW_006' => 'no',
            'STR_STG_001' => ['context', 'problem_diagnosis', 'evidence_or_case'],
            'STR_STG_008' => 'new_substantive_point',
            'TIM_CUE_003' => ['relative_present_language', 'historical_date_or_period'],
            'TIM_CUE_011' => ['none'],
            'VOC_CRD_001' => 'firsthand_lived_or_operational_experience',
            'VOC_PRT_005' => 'absent',
        ];
    }

    /**
     * The human-flavoured fixture, annotated so that every calibrated marker
     * points away from AI. Not a claim about that text: the human-ward
     * participation pathway is *absent* in the release, so the fixture as it
     * reads scores inconclusive. This set exists to exercise the lower verdict.
     *
     * @return array<string, string|list<string>>
     */
    public static function allHumanWard(): array
    {
        return [
            'STR_FLW_005' => 'no',
            'STR_FLW_006' => 'no',
            'STR_STG_001' => ['context', 'evidence_or_case'],
            'STR_STG_008' => 'abrupt_end_without_conclusion',
            'TIM_CUE_003' => ['historical_date_or_period'],
            'TIM_CUE_011' => ['none'],
            'VOC_CRD_001' => 'firsthand_lived_or_operational_experience',
            'VOC_PRT_005' => 'present',
        ];
    }

    /**
     * Every asked-about feature answered on-menu with a value that fires as
     * little released evidence as an answer can.
     *
     * @return array<string, string|list<string>>
     */
    public static function uninformative(): array
    {
        return [
            'STR_FLW_005' => 'no',
            'STR_FLW_006' => 'no',
            'STR_STG_001' => ['context'],
            'STR_STG_008' => 'summary_of_main_points',
            'TIM_CUE_003' => ['relative_present_language'],
            'TIM_CUE_011' => ['none'],
            'VOC_CRD_001' => 'named_role_or_professional_expertise',
            'VOC_PRT_005' => 'absent',
        ];
    }

    public static function aiFlavouredText(): string
    {
        return self::read('ai-flavoured.md');
    }

    public static function humanFlavouredText(): string
    {
        return self::read('human-flavoured.md');
    }

    private static function read(string $name): string
    {
        $path = __DIR__ . '/../Fixtures/' . $name;
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Missing test fixture: %s', $path));
        }

        return $contents;
    }
}
