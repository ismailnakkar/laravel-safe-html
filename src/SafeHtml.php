<?php

declare(strict_types=1);

namespace SafeHtml;

use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Exceptions\PostTooLargeException;
use SplFileInfo;
use Stringable;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Reduces HTML someone typed to formatting and links that are safe to print with {!! !!}. */
#[Singleton] // An app's own binding still wins.
final readonly class SafeHtml
{
    private HtmlSanitizer $sanitizer;

    private int $maxInputLength;

    public function __construct(?HtmlSanitizerConfig $policy = null)
    {
        $policy ??= self::policy();
        $this->maxInputLength = $policy->getMaxInputLength();

        // Symfony's NativeParser minus two bugs: UTF-8 is forced, so a <meta charset> can't re-decode the text, and no
        // </body> is appended, which an unclosed <title> or <style> would print. Drop once Symfony fixes both.
        $this->sanitizer = new HtmlSanitizer($policy, new class implements ParserInterface
        {
            public function parse(string $html, string $context = 'body'): ?Node
            {
                // sanitize() always parses in body.
                return HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR, 'UTF-8')->body;
            }
        });
    }

    /**
     * Trimmed clean HTML, or null when nothing is left. A Stringable is cleaned as its string; any other non-string
     * comes back as is, for the `string` rule.
     *
     * @template T
     *
     * @param  T  $html
     * @return (T is SplFileInfo ? T : (T is string|Stringable ? non-empty-string|null : T))
     *
     * @throws PostTooLargeException past the policy's max input length
     */
    public function clean(mixed $html): mixed
    {
        // A file is Stringable too (its path), and must stay a non-string.
        if ($html instanceof Stringable && ! $html instanceof SplFileInfo) {
            $html = (string)$html;
        }

        if (! is_string($html)) {
            return $html;
        }

        // Refused rather than cut as Symfony would: a cut can land mid-character and clean to null.
        if ($this->maxInputLength !== -1 && strlen($html) > $this->maxInputLength) {
            throw new PostTooLargeException("The HTML is over {$this->maxInputLength} bytes.");
        }

        $clean = trim($this->sanitizer->sanitize($html));

        // An empty editor still sends markup: <p><br></p>, <p>&nbsp;</p>.
        return $clean === '' || preg_replace('~</?p>|<br />|\s~u', '', $clean) === '' ? null : $clean;
    }

    /** The package's policy. Symfony's config is immutable: extend the copy this returns and pass it to the constructor. */
    public static function policy(): HtmlSanitizerConfig
    {
        $policy = (new HtmlSanitizerConfig)
            // An element neither allowed nor dropped below is unwrapped: its text stays, its tag and attributes go.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowElement('a', ['href', 'title', 'target'])
            // window.opener guard: any target becomes _blank; every link gets rel since Symfony can't tie it to target.
            // href is trimmed of spaces and C0 controls first, as browsers do: Symfony would drop it or end it in %20.
            ->withAttributeSanitizer(new class implements AttributeSanitizerInterface
            {
                public function getSupportedElements(): array
                {
                    return ['a'];
                }

                public function getSupportedAttributes(): array
                {
                    return ['href', 'target'];
                }

                public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): string
                {
                    return $attribute === 'target' ? '_blank' : trim($value, "\x00..\x20");
                }
            })
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            // Bounds time for deep nesting (100 KB: ~0.5 s), not memory: a 10 KB mix of distinct unclosed <b a=N> and
            // <p>x makes the parser re-open every <b> in every <p> (1 s, 278 MB). memory_limit is the only ceiling.
            ->withMaxInputLength(100_000);

        foreach ([
            'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        ] as $element) {
            $policy = $policy->allowElement($element);
        }

        // Dropped with their contents. Not style, link or meta: Symfony ignores head elements in a body, so they unwrap.
        foreach ([
            'script', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'option', 'optgroup',
            'textarea', 'label', 'fieldset', 'template', 'noscript', 'base', 'frame', 'frameset', 'audio', 'video',
            'source', 'track', 'canvas', 'portal', 'marquee', 'dialog', 'svg', 'math',
        ] as $element) {
            $policy = $policy->dropElement($element);
        }

        return $policy;
    }
}
