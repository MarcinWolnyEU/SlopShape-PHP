<?php

declare(strict_types=1);

use SlopShape\Text\Html;
use SlopShape\Text\Markdown;

it('finds the title of a document and nothing where there is none', function (): void {
    $markdown = new Markdown();

    expect($markdown->title("# A Real Title\n\nThe body starts here."))->toBe('A Real Title')
        ->and($markdown->title(''))->toBeNull()
    ;
});

it('has no subtitle without a title', function (): void {
    expect((new Markdown())->subtitle("# T\n\nDeck line\n\nBody", null))->toBeNull();
});

it('takes the standfirst under a title that heads the document', function (): void {
    $text = "# A Real Title\n\nA short standfirst\n\nThe body is a long sentence that ends with a full stop.";

    expect((new Markdown())->subtitle($text, 'A Real Title'))->toBe('A short standfirst');
});

it('finds a subtitle after a title that the caller supplied, wherever it sits', function (): void {
    $markdown = new Markdown();
    $text = "Alpha beta\n\nGamma delta\n\nThird line";

    // "Alpha beta" is what the document would call its title, so a caller who
    // says the title is "Gamma delta" is asking about what follows that.
    expect($markdown->subtitle($text, 'Gamma delta'))->toBe('Third line')
        ->and($markdown->subtitle($text, 'A title that is not in the text'))->toBeNull()
    ;
});

it('does not mistake unclosed front matter for a title block', function (): void {
    $markdown = new Markdown();

    expect($markdown->title("---\ntitle: never closed\n\n# Heading\n\nBody."))->toBeString();
});

it('skips front matter when it closes', function (): void {
    expect((new Markdown())->title("---\nlayout: post\n---\n# After Front Matter\n\nBody."))->toBe('After Front Matter');
});

it('reads a body element as markup even without a doctype', function (): void {
    expect((new Html())->looksLikeHtml('<body><p>Hello there.</p></body>'))->toBeTrue();
});

it('keeps text inside elements that merely start like script', function (): void {
    $text = (new Html())->toText('<p>one</p><scripts>kept</scripts><script>dropped()</script><p>two</p>');

    expect($text)->toContain('kept')
        ->and($text)->not->toContain('dropped')
        ->and($text)->toContain('one')
        ->and($text)->toContain('two')
    ;
});

it('does not end a script at a closing tag that only starts like it', function (): void {
    $text = (new Html())->toText('<p>one</p><script>a</scripts>b</script><p>two</p>');

    expect($text)->not->toContain('b')
        ->and($text)->toContain('two')
    ;
});

it('stops dropping at a script that is never closed, rather than running to the end', function (): void {
    $text = (new Html())->toText('<p>before</p><script>alert(1)');

    expect($text)->toContain('before');
});

it('stops at an opening tag with no closing bracket', function (): void {
    $text = (new Html())->toText('<p>before</p><script');

    expect($text)->toContain('before');
});

it('scrubs invalid UTF-8 rather than passing it on', function (): void {
    $text = (new Markdown())->toPlainText("bad \xC3\x28 bytes");

    expect(mb_check_encoding($text, 'UTF-8'))->toBeTrue()
        ->and($text)->toContain('bad')
        ->and($text)->toContain('bytes')
    ;
});
