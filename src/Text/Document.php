<?php

declare(strict_types=1);

namespace SlopShape\Text;

/**
 * A text document, normalised for measuring.
 *
 * "Normalised" means: HTML flattened to prose, inline Markdown noise removed,
 * title and subtitle guessed and cut off the body. Word count is then taken
 * over the body only, because a title is not prose and the study's length
 * classes are defined over the article body.
 *
 * Everything here is deterministic. This class measures; it never guesses what
 * a document means.
 */
final readonly class Document {
    /**
     * A word as the study counts one: a Latin-script letter followed by letters,
     * combining marks, apostrophes or hyphens. Accented Latin letters count, so
     * "café" is one word; words in other scripts are not counted here.
     */
    private const string WORD_PATTERN = "~\\p{Latin}[\\p{Latin}\\p{M}'\u{2019}-]*~u";

    /** A word in any script, used for the Latin-script ratio. */
    private const string ANY_WORD_PATTERN = '~[\p{L}\p{M}]+~u';

    private const string LATIN_WORD_PATTERN = '~^[\p{Latin}\p{M}]+$~u';

    private function __construct(
        public string $raw,
        public string $text,
        public string $bodyText,
        public ?string $title,
        public ?string $subtitle,
        public int $wordCount,
        public int $allWordCount,
        public int $latinWordCount,
        private string $promptBody = '',
    ) {
    }

    /**
     * Fraction of words written in the Latin script.
     *
     * The study scored English text only, so a document that is not mostly
     * Latin is outside the instrument's calibrated range and the detector says
     * so rather than guessing. This is a proxy: the instrument has no word list,
     * and a Latin-script document in another language passes it.
     */
    public function latinWordRatio(): float
    {
        return 0 === $this->allWordCount
            ? 1.0
            : $this->latinWordCount / $this->allWordCount;
    }

    /**
     * The text as sent to the model: the post without its title, length-capped.
     *
     * The title is left out, as the study scored most human posts without one.
     * The subtitle stays where it was: it is prose, and it is only a guess, so a
     * wrong guess must not cost the model a paragraph.
     *
     * The cap protects the prompt, not the measurement. Word count is taken
     * from the whole body, so truncation cannot move the one feature this
     * package measures itself.
     */
    public function promptText(int $maxChars): string
    {
        return mb_substr($this->promptBody, 0, $maxChars);
    }

    public static function fromString(string $raw, ?Html $html = null, ?Markdown $markdown = null): self
    {
        $html ??= new Html();
        $markdown ??= new Markdown();

        // Invalid UTF-8 would make every /u pattern fail; repair it up front.
        $source = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_scrub($raw, 'UTF-8');

        $text = $html->looksLikeHtml($source) ? $html->toText($source) : $source;
        $text = $markdown->toPlainText($text);

        $located = $markdown->locateTitle($text);
        $title = $located['text'] ?? null;
        $locatedSubtitle = null === $located ? null : $markdown->locateSubtitle($text, $located['end']);
        $subtitle = $locatedSubtitle['text'] ?? null;
        $body = self::cutHead($text, $located['end'] ?? null, $locatedSubtitle['end'] ?? null);

        $bodyWords = \count(Pcre::all(self::WORD_PATTERN, $body));
        $allWords = Pcre::all(self::ANY_WORD_PATTERN, $body);
        $latinWords = array_filter(
            $allWords,
            static fn (string $word): bool => Pcre::matches(self::LATIN_WORD_PATTERN, $word),
        );

        return new self(
            raw: $raw,
            text: $text,
            bodyText: $body,
            title: $title,
            subtitle: $subtitle,
            wordCount: $bodyWords,
            allWordCount: \count($allWords),
            latinWordCount: \count($latinWords),
            promptBody: self::cutHead($text, $located['end'] ?? null, null),
        );
    }

    /**
     * Drop the title and subtitle (or, for the prompt, the title alone) from
     * the text.
     *
     * The cut is made at the byte offsets where the title and subtitle were
     * found, never by searching for their text: the same words may appear
     * earlier, and cutting at that occurrence would delete real prose.
     *
     * If cutting them would swallow most of the document, the title guess was
     * wrong and the whole text is kept: a mis-detected title must not silently
     * delete the article.
     */
    private static function cutHead(string $text, ?int $titleEnd, ?int $subtitleEnd): string
    {
        $cut = max($titleEnd ?? 0, $subtitleEnd ?? 0);

        $body = 0 === $cut ? $text : ltrim(substr($text, $cut), "\n");

        return mb_strlen($body) < 0.4 * mb_strlen($text) ? $text : $body;
    }
}
