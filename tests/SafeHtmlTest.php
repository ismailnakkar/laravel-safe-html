<?php

declare(strict_types=1);

namespace SafeHtml\Tests;

use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SafeHtml\SafeHtml;
use Symfony\Component\String\UnicodeString;

final class SafeHtmlTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cleanCases(): array
    {
        return [
            'every allowed tag and a link' => [
                '<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6><p><strong>g</strong><b>h</b><em>i</em><i>j</i><u>k</u><s>l</s><strike>m</strike><sub>n</sub><sup>o</sup><br>p</p><hr>'
                . '<ul><li>q</li></ul><ol><li>r</li></ol><blockquote>s</blockquote><pre><code>t</code></pre><p><a href="https://example.test/page" title="Docs">u</a></p>',
                '<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6><p><strong>g</strong><b>h</b><em>i</em><i>j</i><u>k</u><s>l</s><strike>m</strike><sub>n</sub><sup>o</sup><br />p</p><hr />'
                . '<ul><li>q</li></ul><ol><li>r</li></ol><blockquote>s</blockquote><pre><code>t</code></pre><p><a href="https://example.test/page" title="Docs" rel="noopener noreferrer">u</a></p>',
            ],
            'a link typed with spaces around it'        => ['<a href=" https://example.test/page ">x</a>', '<a href="https://example.test/page" rel="noopener noreferrer">x</a>'],
            'a link with a control character before it' => ["<a href=\"\x0Chttps://example.test/page\">x</a>", '<a href="https://example.test/page" rel="noopener noreferrer">x</a>'],
            'relative and mailto links'                 => [
                '<a href="/pricing">a</a><a href="#faq">b</a><a href="mailto:hi@example.test">c</a>',
                '<a href="/pricing" rel="noopener noreferrer">a</a><a href="#faq" rel="noopener noreferrer">b</a><a href="mailto:hi&#64;example.test" rel="noopener noreferrer">c</a>',
            ],
            'a script, with its contents'       => ['<p>before</p><script>alert(1)</script><p>after</p>', '<p>before</p><p>after</p>'],
            'an image with an error handler'    => ['<p>a<img src="x" onerror="alert(1)">b</p>', '<p>ab</p>'],
            'a javascript: link keeps its text' => ['<a href="javascript:alert(1)">click me</a>', '<a rel="noopener noreferrer">click me</a>'],
            // Disguised javascript: schemes that browsers have run.
            'javascript: split by a tab'                     => ["<a href=\"java\tscript:alert(1)\">x</a>", '<a rel="noopener noreferrer">x</a>'],
            'javascript: split by a newline'                 => ["<a href=\"java\nscript:alert(1)\">x</a>", '<a rel="noopener noreferrer">x</a>'],
            'javascript: split by a NUL'                     => ["<a href=\"jav\x00ascript:alert(1)\">x</a>", '<a rel="noopener noreferrer">x</a>'],
            'javascript: behind a space, mixed case'         => ['<a href=" JaVaScRiPt:alert(1)">x</a>', '<a rel="noopener noreferrer">x</a>'],
            'a data: link'                                   => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>', '<a rel="noopener noreferrer">x</a>'],
            'svg, with its contents'                         => ['<p>before</p><svg onload="alert(1)"><circle r="1"/><text>drawn</text></svg><p>after</p>', '<p>before</p><p>after</p>'],
            'math, with its contents'                        => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)></style></mglyph></table></mtext></math><p>ok</p>', '<p>ok</p>'],
            'a form, with its contents'                      => ['<form action="https://evil.test"><input name="password"><button>Sign in</button></form><p>ok</p>', '<p>ok</p>'],
            'style, class, id, data and on* attributes'      => ['<p id="x" class="y" style="position:fixed;inset:0" data-x="1" onclick="alert(1)">text</p>', '<p>text</p>'],
            'style, class and id on a link'                  => ['<a href="https://example.test" style="position:fixed;inset:0" class="x" id="y">x</a>', '<a href="https://example.test" rel="noopener noreferrer">x</a>'],
            'malformed nesting'                              => ['<p><b>bold<p>next</b><scr<script>ipt>alert(1)</script>', '<p><b>bold</b></p><p><b>next</b>ipt&gt;alert(1)</p>'],
            'an unknown element is unwrapped'                => ['<p><marquee-ish><custom-tag>keep this</custom-tag></marquee-ish></p>', '<p>keep this</p>'],
            'a target opens a new tab without window.opener' => ['<a href="https://example.test" target="somewindow">x</a>', '<a href="https://example.test" target="_blank" rel="noopener noreferrer">x</a>'],
            'text stays escaped'                             => ['<p>5 &lt; 6 &amp; 7 &gt; 6</p>', '<p>5 &lt; 6 &amp; 7 &gt; 6</p>'],
            'a meta charset does not re-decode the text'     => ['<meta http-equiv="Content-Type" content="text/html; charset=windows-1252"><p>Café — مرحبا</p>', '<p>Café — مرحبا</p>'],
            'an unclosed title adds no stray tag'            => ['<p>a</p><title>b', '<p>a</p>b'],
            'an unclosed plaintext adds no stray tag'        => ['<p>a</p><plaintext>b', '<p>a</p>b'],
        ];
    }

    #[DataProvider('cleanCases')]
    public function test_it_keeps_formatting_and_links_and_drops_what_runs(string $html, string $clean): void
    {
        $this->assertSame($clean, (new SafeHtml)->clean($html));
    }

    public function test_clean_trims_and_returns_null_when_nothing_is_left(): void
    {
        $safe = new SafeHtml;

        $this->assertNull($safe->clean('<script>alert(1)</script>'));
        // An empty editor: Quill, Tiptap, CKEditor 4.
        $this->assertNull($safe->clean('<p><br></p>'));
        $this->assertNull($safe->clean('<p></p>'));
        $this->assertNull($safe->clean('<p>&nbsp;</p>'));
        // Markup without text is kept: only <p>, <br /> and whitespace count as nothing.
        $this->assertSame('<hr />', $safe->clean('<hr>'));
        $this->assertSame('<p>x</p>', $safe->clean(' <p>x</p> '));
    }

    public function test_a_non_string_comes_back_as_is(): void
    {
        $safe = new SafeHtml;
        $file = UploadedFile::fake()->create('a.html');

        $this->assertNull($safe->clean(null));
        $this->assertSame(['<script>'], $safe->clean(['<script>']));
        $this->assertSame($file, $safe->clean($file));
    }

    public function test_a_stringable_is_cleaned_as_its_string(): void
    {
        $safe = new SafeHtml;

        $this->assertSame('<p>x</p>', $safe->clean(str('<script>a</script><p>x</p>')));
        $this->assertSame('<p>x</p>', $safe->clean(new HtmlString('<p onclick="a">x</p>')));
        $this->assertSame('<p>x</p>', $safe->clean(new UnicodeString('<p>x</p><script>a</script>')));
    }

    public function test_input_past_the_max_length_is_refused_not_cut(): void
    {
        $this->assertSame(str_repeat('a', 100_000), (new SafeHtml)->clean(str_repeat('a', 100_000)));
        $this->assertSame(str_repeat('a', 100_001), (new SafeHtml(SafeHtml::policy()->withMaxInputLength(-1)))->clean(str_repeat('a', 100_001)));

        $this->expectException(PostTooLargeException::class);
        (new SafeHtml)->clean(str_repeat('a', 100_001));
    }

    public function test_an_app_policy_is_applied_and_keeps_the_utf8_parser(): void
    {
        $withImages = new SafeHtml(SafeHtml::policy()->allowElement('img', ['src', 'alt']));

        $this->assertSame(
            '<p><img src="https://example.test/a.png" alt="A" /></p>',
            $withImages->clean('<p><img src="https://example.test/a.png" alt="A" onerror="alert(1)"></p>'),
        );
        $this->assertSame('<p>Café — مرحبا</p>', $withImages->clean('<meta http-equiv="Content-Type" content="text/html; charset=windows-1252"><p>Café — مرحبا</p>'));
    }

    /** The Laravel 13.0 floor: an unbound default parameter keeps its default, and #[Singleton] shares the instance. */
    public function test_the_container_builds_one_with_the_package_policy(): void
    {
        $safe = $this->app->make(SafeHtml::class);

        // An injected empty config would give null.
        $this->assertSame('<p>a</p>', $safe->clean('<p onclick="x()">a</p>'));
        $this->assertSame($safe, $this->app->make(SafeHtml::class));
    }
}
