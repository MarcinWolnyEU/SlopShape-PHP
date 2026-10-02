<?php

declare(strict_types=1);

use SlopShape\Exception\PcreFailure;
use SlopShape\Text\Document;
use SlopShape\Text\Html;
use SlopShape\Text\Markdown;
use SlopShape\Text\Pcre;

it('counts words the way the study does, over the body', function (): void {
    $document = Document::fromString("# A Title\n\nThe quick brown fox jumps over the lazy dog.\n");

    expect($document->wordCount)->toBe(9)
        ->and($document->title)->toBe('A Title')
        ->and($document->bodyText)->toContain('quick brown fox')
        ->and($document->bodyText)->not->toContain('A Title')
    ;
});

it('keeps hyphenated and apostrophised words whole', function (): void {
    expect(Document::fromString("It's a state-of-the-art tool.\n")->wordCount)->toBe(4);
});

it('finds a title in the first line when there are no headings', function (): void {
    expect(Document::fromString("The Tuesday Repair Log\n\nI keep a text file.\n")->title)
        ->toBe('The Tuesday Repair Log')
    ;
});

it('does not mistake a sentence for a title', function (): void {
    expect(Document::fromString("I keep a text file for the things that broke this week.\n")->title)
        ->toBeNull()
    ;
});

it('does not mistake a list item for a title', function (): void {
    expect(Document::fromString("- Audit your pipelines\n- Consolidate ingestion\n")->title)
        ->toBeNull()
    ;
});

it('keeps the whole document when cutting a guessed title would swallow it', function (): void {
    // A "title" that is most of the document must not delete the article.
    $document = Document::fromString("Only A Few Words\n\nand then nothing else at all follows\n");

    expect($document->bodyText)->toContain('nothing else');
});

it('flattens HTML to prose with its block skeleton intact', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html><head><style>.a{color:red}</style><script>var x = "hidden words";</script></head>
        <body>
        <h1>Quarterly Report</h1>
        <p>Revenue was <strong>up</strong> 4% &amp; costs were flat.</p>
        <ul><li>First item</li><li>Second item</li></ul>
        <p>See the <a href="https://example.com/report">full report</a>.</p>
        </body></html>
        HTML;

    $document = Document::fromString($html);

    expect($document->title)->toBe('Quarterly Report')
        ->and($document->text)->toContain('Revenue was up 4% & costs were flat')
        ->and($document->text)->toContain('- First item')
        ->and($document->text)->not->toContain('color:red')
        ->and($document->text)->not->toContain('hidden words')
    ;
});

it('leaves prose that merely mentions a tag alone', function (): void {
    $text = 'Use the <div> tag when you need a block, and remember to close it.';

    expect(Document::fromString($text)->text)->toContain('<div>');
});

it('keeps link targets, because routing off the page is a marker', function (): void {
    $document = Document::fromString("Read the [migration playbook](https://example.com/playbook) first.\n");

    expect($document->text)->toContain('migration playbook (https://example.com/playbook)');
});

it('strips emphasis and inline code but keeps the words', function (): void {
    $document = Document::fromString("Run `composer test` with **the** _full_ suite.\n");

    expect($document->text)->toContain('composer test')
        ->and($document->text)->toContain('the')
        ->and($document->text)->toContain('full')
        ->and($document->text)->not->toContain('**')
        ->and($document->text)->not->toContain('`')
    ;
});

it('collapses code fences so their contents are not counted as prose', function (): void {
    $before = Document::fromString("Some prose here.\n\n```php\necho 'and a great deal of code';\n```\n");
    $after = Document::fromString("Some prose here.\n\n```php\necho 'x';\n```\n");

    expect($before->wordCount)->toBe(3)
        ->and($after->wordCount)->toBe(3)
    ;
});

it('measures the Latin-script share of a document', function (): void {
    expect(Document::fromString('All of this is in English.')->latinWordRatio())->toBe(1.0)
        ->and(Document::fromString('これは日本語です。')->latinWordRatio())->toBe(0.0)
    ;
});

it('reports a full ratio for a document with no words at all', function (): void {
    expect(Document::fromString('')->latinWordRatio())->toBe(1.0)
        ->and(Document::fromString('')->wordCount)->toBe(0)
    ;
});

it('caps the prompt text without changing the word count', function (): void {
    $text = str_repeat('word ', 5_000);
    $document = Document::fromString($text);

    expect($document->promptText(100))->toHaveLength(100)
        ->and($document->wordCount)->toBe(5_000)
    ;
});

it('normalises whitespace runs and repeated blank lines', function (): void {
    expect((new Markdown())->toPlainText("a    b\n\n\n\nc\n"))->toBe("a b\n\nc");
});

it('finds a standfirst under the title', function (): void {
    $document = Document::fromString(
        "# The Repair Log\n\nA text file for the things that broke this week\n\nTuesday, 14:10. The kettle.\n",
    );

    expect($document->title)->toBe('The Repair Log')
        ->and($document->subtitle)->toBe('A text file for the things that broke this week')
    ;
});

it('sends the standfirst to the model ahead of the body, and not the title', function (): void {
    $document = Document::fromString(
        "# The Repair Log\n\nA text file for the things that broke this week\n\nTuesday, 14:10. The kettle.\n",
    );

    expect($document->promptText(1_000))
        ->toBe("A text file for the things that broke this week\n\nTuesday, 14:10. The kettle.")
        ->and($document->promptText(6))->toBe('A text')
    ;
});

it('sends the body alone when there is no standfirst', function (): void {
    $body = "- one\n- two\n\n" . str_repeat('The kettle has stopped boiling properly again. ', 5);

    expect(Document::fromString("# The Repair Log\n\n{$body}")->promptText(1_000))
        ->not->toContain('Repair Log')
        ->toStartWith('- one')
    ;
});

it('keeps a title in the prompt rather than cut away most of a tiny document', function (): void {
    // The same guard as the body: a title guess that would leave less than 40%
    // of the text is more likely wrong than right.
    expect(Document::fromString("# The Repair Log\n\n- one\n- two\n")->promptText(1_000))->toContain('Repair Log');
});

it('does not call a list a standfirst', function (): void {
    $document = Document::fromString("# The Repair Log\n\n- one\n- two\n");

    expect($document->subtitle)->toBeNull();
});

it('recognises a fenced, self-closing and unbalanced document as HTML only when it must', function (): void {
    $html = new Html();

    expect($html->looksLikeHtml('<html><body><p>hi</p></body></html>'))->toBeTrue()
        ->and($html->looksLikeHtml('<p>hi</p>'))->toBeTrue()
        ->and($html->looksLikeHtml('a <div> tag in prose'))->toBeFalse()
        ->and($html->looksLikeHtml('plain text'))->toBeFalse()
    ;
});

/**
 * @return string n filler words
 */
function fillerWords(int $n): string
{
    return trim(str_repeat('word ', $n));
}

it('only takes a level-one heading at the top as the title (H3)', function (): void {
    $document = Document::fromString(fillerWords(300) . "\n\n# Details\n\n" . fillerWords(600) . "\n");

    expect($document->title)->not->toBe('Details')
        ->and($document->wordCount)->toBeGreaterThanOrEqual(900)
    ;
});

it('cuts the title at its own position, not at an earlier mention (H3)', function (): void {
    $document = Document::fromString(
        "# Notes\n\nThe word Notes appears here first. " . fillerWords(100) . "\n\n## Notes\n\n" . fillerWords(100) . "\n",
    );

    expect($document->title)->toBe('Notes')
        ->and($document->bodyText)->toContain('appears here first')
    ;
});

it('accepts a top heading after blank lines or front matter (H3)', function (): void {
    $document = Document::fromString("---\ntitle: x\n---\n\n\n# Real Title\n\n" . fillerWords(50) . "\n");

    expect($document->title)->toBe('Real Title')
        ->and($document->wordCount)->toBe(50)
    ;
});

it('repairs invalid UTF-8 instead of losing the text (H4)', function (): void {
    $document = Document::fromString(fillerWords(900) . " \x92 more\n");

    expect($document->wordCount)->toBe(901)
        ->and($document->allWordCount)->toBe(901)
    ;
});

it('survives a large closed script element (H5)', function (): void {
    $html = '<html><body><p>Hello there friend</p><script>' . str_repeat('var a = 1; ', 100_000)
        . '</script><p>and goodbye</p></body></html>';

    expect(Document::fromString($html)->wordCount)->toBe(5);
});

it('survives a stray underscore before a huge document (H5)', function (): void {
    $document = Document::fromString("This has a stray _ mark.\n\n" . str_repeat('word ', 240_000));

    expect($document->wordCount)->toBe(240_005);
});

it('survives an unclosed code fence before a huge document (H5)', function (): void {
    $document = Document::fromString("Intro line here.\n\n```\n" . str_repeat('word ', 240_000));

    expect($document->wordCount)->toBe(240_003);
});

it('throws rather than returning nothing when a pattern fails (H5)', function (): void {
    ini_set('pcre.backtrack_limit', '10');
    ini_set('pcre.jit', '0');

    try
    {
        Pcre::replace('~(a+)+$~', '', str_repeat('a', 30) . 'b');
    } finally
    {
        ini_restore('pcre.backtrack_limit');
        ini_restore('pcre.jit');
    }
})->throws(PcreFailure::class);

it('leaves intra-word underscores alone (M2a)', function (): void {
    $document = Document::fromString('Set max_retries and user_id, then read PUR_OUT_003 and snake_case_name.');

    expect($document->text)->toContain('max_retries')
        ->and($document->text)->toContain('user_id')
        ->and($document->text)->toContain('PUR_OUT_003')
        ->and($document->text)->toContain('snake_case_name')
    ;
});

it('still strips real underscore emphasis but never across paragraphs (M2a)', function (): void {
    $markdown = new Markdown();

    expect($markdown->toPlainText('a _very_ big __deal__ here'))->toBe('a very big deal here')
        ->and($markdown->toPlainText("open _one\n\ntwo_ close"))->toBe("open _one\n\ntwo_ close")
        ->and($markdown->toPlainText("a *b* and **c** and *x\n\ny*"))->toBe("a b and c and *x\n\ny*")
    ;
});

it('does not treat Markdown that mentions a tag as HTML (M2b)', function (): void {
    $html = new Html();
    $markdown = "# Title\n\nWrite `<p>hi</p>` for a paragraph.\n\n```html\n<div>x</div>\n```\n\n- one\n- two\n";

    expect($html->looksLikeHtml($markdown))->toBeFalse()
        ->and($html->looksLikeHtml("Use <p>hi</p> here.\n\n# One\n\n- a\n- b\n- c\n"))->toBeFalse()
        ->and($html->looksLikeHtml('<div><p>Real</p></div>'))->toBeTrue()
    ;

    $prose = Document::fromString("# Budget\n\nWrite `<p>hi</p>` first. " . 'The cost<budget and risk> stays. ' . fillerWords(50) . "\n");

    expect($prose->text)->toContain('cost<budget and risk>');
});

it('closes code fences in CRLF documents (M3)', function (): void {
    $lf = "Intro words here now.\n\n```\ncode one two three\n```\n\nAfter fence.\n";
    $crlf = str_replace("\n", "\r\n", $lf);

    expect(Document::fromString($crlf)->wordCount)->toBe(Document::fromString($lf)->wordCount);
});

it('counts accented Latin words and treats them as Latin', function (): void {
    $document = Document::fromString('Our naïve café résumé from Zürich.');

    expect($document->wordCount)->toBe(6)
        ->and($document->latinWordRatio())->toBe(1.0)
    ;
});
