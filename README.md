# laravel-safe-html

Cleans HTML your users type (an announcement, a bio) down to basic formatting and links, so you can print it with
`{!! !!}`. Built on Symfony's HtmlSanitizer.

It also ships `MaxHtmlLength`, a length rule for cleaned HTML that counts `&#039;` as one character, not six.

## Install

Needs PHP 8.4+ and Laravel 13.

```bash
composer require ismailnakkar/laravel-safe-html
```

There is nothing to publish or register.

## Usage

Clean the field in its form request, before the rules run:

```php
use Illuminate\Foundation\Http\FormRequest;
use SafeHtml\Rules\MaxHtmlLength;
use SafeHtml\SafeHtml;

class AnnouncementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        SafeHtml::cleanInput($this, 'content');
    }

    public function rules(): array
    {
        return ['content' => ['nullable', 'string', new MaxHtmlLength(10_000)]];
    }
}
```

`content` is clean in `validated()`, so print the saved column raw:

```blade
{!! $announcement->content !!}
```

`cleanInput()` runs `clean()` on each field you name: `SafeHtml::cleanInput($this, 'content', 'bio')`.

- A field that wasn't sent stays unsent, so an update without it keeps the column.
- A dotted name (`'meta.bio'`) reaches a nested field, and `*` stands for every item at its level, as in a rule
  (`'translations.*.content'`).
- A name a rule would read differently throws `InvalidArgumentException`: a top-level `*`, a `*` inside a level
  (`'b*'`), an escaped dot, `{first}` or `{last}`.

Call it in `prepareForValidation()`, so the rules and `validated()` see the clean value.

## Cleaning other values

Anything that writes the column without the request (an import, a seeder) must call `clean()` itself, or clean on
output:

```blade
{!! app(\SafeHtml\SafeHtml::class)->clean($row->content) !!}
```

`clean()` takes a string or any `Stringable` (`str($html)`, an `AsStringable` cast, an `HtmlString`), and returns:

- the clean HTML, trimmed;
- `null` when nothing is left: `''`, `'   '`, `'<script>…</script>'`, an empty editor's `<p><br></p>` or
  `<p>&nbsp;</p>`, or input that isn't valid UTF-8.

`null` stays `null`. Any other value (an array, an uploaded file) comes back unchanged, so the `string` rule still
refuses it.

## Input limit

`clean()` refuses input over 100,000 bytes with `PostTooLargeException` instead of cutting it. That is a 413 response,
not a validation error, so a form does not come back with its input, and in the Blade snippet above one oversized row
fails the whole page. Raise the limit with `withMaxInputLength()` ([Customising the policy](#customising-the-policy)).

The limit bounds time, not memory: a crafted 10 KB body can make `clean()` allocate nearly 300 MB. PHP's
`memory_limit` stops it with a fatal error, so never call `clean()` in a process running with `memory_limit=-1`.

## What survives

- `p br hr strong b em i u s strike sub sup ul ol li blockquote pre code h1`–`h6`, without attributes.
- `a` with `href`, `title` and `target`. Links may be `http`, `https`, `mailto` or relative; any other (`tel:`,
  `javascript:`, `data:`) loses its `href` and keeps its text. Any `target` becomes `_blank`, and every link gets
  `rel="noopener noreferrer"`.
- Every other attribute is removed (`style`, `class`, `id`, `on*`, `data-*`).
- `script`, `iframe`, `object`, `embed`, `form` and its fields, `template`, `noscript`, `audio`, `video`, `canvas`,
  `dialog`, `svg`, `math` and similar are removed with their contents (full list in `src/SafeHtml.php`).
- Every other element loses its tag and keeps its text, so neighbouring blocks run together (`<div>a</div><div>b</div>`
  becomes `ab`, and so do table cells). That covers `div`, `span`, `section`, `table`, and `style`, so pasted CSS shows
  as text. `img` disappears.

## Customising the policy

`SafeHtml::policy()` returns the package's Symfony `HtmlSanitizerConfig`. Its methods return a changed copy, so chain
them and bind the result in `AppServiceProvider::register()`:

```php
use SafeHtml\SafeHtml;

$this->app->singleton(SafeHtml::class, fn () => new SafeHtml(
    SafeHtml::policy()->allowElement('img', ['src', 'alt'])->withMaxInputLength(200_000),
));
```

Image `src` accepts `http`, `https` and `data:`; add `->allowRelativeMedias()` for paths like `/storage/a.png`.

Start from `SafeHtml::policy()`, not `new HtmlSanitizerConfig`: a bare config has none of the rules above and refuses
input over 20,000 bytes. Without your binding, the container shares one `SafeHtml` on the package's policy.

## Length

Use `new MaxHtmlLength($max)` instead of `max:` on cleaned HTML. Both count tags, but `max:` counts each escaped
character as stored (`'` is stored as `&#039;`, six characters) where `MaxHtmlLength` counts it as one.

- It fails with Laravel's `validation.max.string` message, so your translations and `attributes()` apply. To override
  it in `messages()`, use the key `content.max_html_length` (`:max` is not filled in there).
- A non-string passes; the `string` rule refuses it.
- On MySQL and MariaDB, stored HTML can take up to 8 bytes per counted character (`＜` is stored as `&#xFF1C;`): a
  `text` column (65,535 bytes) holds a cap up to 8,191, so `MaxHtmlLength(10_000)` needs `mediumText`.

## API in brief

| | |
| --- | --- |
| `SafeHtml::cleanInput(Request $request, string ...$fields)` | Cleans the named request fields in place |
| `app(SafeHtml::class)->clean(mixed $html)` | The clean HTML, or null when nothing is left; a `Stringable` is cleaned as its string, any other non-string comes back as is |
| `SafeHtml::policy()` | The package's `HtmlSanitizerConfig`, to extend and pass to `new SafeHtml($policy)` |
| `new MaxHtmlLength(int $max)` | A `max:` that counts each entity as one character |

## Testing your app

Nothing to fake: post the HTML to your route and assert on what was stored, as in production.

## Upgrading

### From 0.1

`cleanInput()` takes `*` below the top level (`'blocks.*.body'`) instead of throwing. Nothing to change.

## Licence

MIT, see [LICENSE](LICENSE).
