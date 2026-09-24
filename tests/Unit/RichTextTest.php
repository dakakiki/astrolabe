<?php

namespace Tests\Unit;

use App\Support\RichText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RichTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeMarkup(): array
    {
        return [
            'script' => ['<p>a<script>alert(1)</script></p>', '<p>a</p>'],
            'event handler' => ['<p onmouseover="x()">a</p>', '<p>a</p>'],
            'javascript link' => ['<p><a href="javascript:alert(1)">a</a></p>', '<p><a rel="noopener noreferrer nofollow" target="_blank">a</a></p>'],
            'data link' => ['<p><a href="data:text/html;base64,PHNjcmlwdD4=">a</a></p>', '<p><a rel="noopener noreferrer nofollow" target="_blank">a</a></p>'],
            'image' => ['<p><img src="https://tracker.example/pixel.gif">a</p>', '<p>a</p>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe><p>a</p>', '<p>a</p>'],
            'style attribute' => ['<p style="position:fixed">a</p>', '<p>a</p>'],
            'form' => ['<form action="https://evil.example"><input name="password"></form><p>a</p>', '<p>a</p>'],
        ];
    }

    #[DataProvider('unsafeMarkup')]
    public function test_unsafe_markup_is_removed(string $input, string $expected): void
    {
        $this->assertSame($expected, RichText::sanitize($input));
    }

    public function test_editor_formatting_survives(): void
    {
        $html = '<h2>Title</h2><p><strong>b</strong> <em>i</em> <u>u</u> <s>s</s> <code>c</code></p>'
            .'<ul><li>one</li></ul><ol><li>two</li></ol><blockquote><p>q</p></blockquote><hr />'
            .'<p><a href="https://example.com" rel="noopener noreferrer nofollow" target="_blank">link</a></p>';

        $this->assertSame($html, RichText::sanitize($html));
    }

    public function test_empty_editors_become_null(): void
    {
        $this->assertNull(RichText::sanitize(null));
        $this->assertNull(RichText::sanitize(''));
        $this->assertNull(RichText::sanitize('<p></p>'));
        $this->assertNull(RichText::sanitize('<p> <br> </p><script>x</script>'));
    }

    public function test_the_empty_paragraph_the_editor_leaves_at_the_end_is_dropped(): void
    {
        $this->assertSame(
            '<p>One</p><ul><li><p>Two</p></li></ul>',
            RichText::sanitize('<p>One</p><ul><li><p>Two</p></li></ul><p></p><p><br></p>'),
        );
    }

    public function test_long_text_is_not_cut(): void
    {
        $long = '<p>'.str_repeat('Saturn returns. ', 10_000).'</p>';

        $this->assertSame($long, RichText::sanitize($long));
    }

    public function test_plain_text_reads_as_one_line(): void
    {
        $this->assertSame('Title One two & three', RichText::toPlainText('<h2>Title</h2><p>One</p><p>two &amp; three</p>'));
        $this->assertSame('Title One…', RichText::toPlainText('<h2>Title</h2><p>One</p><p>two</p>', 9));
    }
}
