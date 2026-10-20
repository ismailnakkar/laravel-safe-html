<?php

declare(strict_types=1);

namespace SafeHtml\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SafeHtml\SafeHtml;

final class CleanInputTest extends TestCase
{
    public function test_a_form_request_validates_the_clean_fields(): void
    {
        $this->app['router']->post('/', fn (AnnouncementRequest $request) => $request->validated());

        $this->postJson('/', ['content' => '<p onclick="x">Hi</p><script>a</script>', 'bio' => '<p><br></p>'])
            ->assertExactJson(['content' => '<p>Hi</p>', 'bio' => null]);
    }

    public function test_a_field_that_was_not_sent_stays_unsent(): void
    {
        $request = Request::create('/', 'PATCH', ['title' => 'x']);

        SafeHtml::cleanInput($request, 'content');

        $this->assertSame(['title' => 'x'], $request->all());
    }

    public function test_an_uploaded_file_is_left_for_the_string_rule(): void
    {
        $file = UploadedFile::fake()->create('a.html');
        $request = Request::create('/', 'POST', files: ['content' => $file]);

        SafeHtml::cleanInput($request, 'content');

        $this->assertSame($file, $request->all()['content']);
    }

    public function test_a_dotted_name_cleans_a_nested_field(): void
    {
        $request = Request::create('/', 'POST', ['meta' => ['bio' => '<p onclick="x">Me</p>', 'name' => 'Ann']]);

        SafeHtml::cleanInput($request, 'meta.bio');

        $this->assertSame(['bio' => '<p>Me</p>', 'name' => 'Ann'], $request->input('meta'));
    }

    public function test_a_parent_named_after_its_child_keeps_the_child_clean(): void
    {
        $request = Request::create('/', 'POST', ['meta' => ['bio' => '<p onclick="x">Me</p>']]);

        SafeHtml::cleanInput($request, 'meta.bio', 'meta');

        $this->assertSame(['bio' => '<p>Me</p>'], $request->input('meta'));
    }

    public function test_a_wildcard_cleans_every_item_that_was_sent(): void
    {
        $request = Request::create('/', 'POST', ['blocks' => [['body' => '<p onclick="x">A</p>'], ['title' => 'B'], ['body' => ['x']]]]);

        SafeHtml::cleanInput($request, 'blocks.*.body');

        $this->assertSame([['body' => '<p>A</p>'], ['title' => 'B'], ['body' => ['x']]], $request->input('blocks'));
    }

    public function test_a_key_holding_a_dot_reaches_validated_clean(): void
    {
        $this->app['router']->post('/', fn (TranslationsRequest $request) => $request->validated());

        $this->postJson('/', ['translations' => ['f.r' => ['content' => '<script>a</script><p>Hi</p>']]])
            ->assertExactJson(['translations' => ['f.r' => ['content' => '<p>Hi</p>']]]);
    }

    public function test_a_collection_parent_is_walked(): void
    {
        $request = Request::create('/', 'POST');
        $request->merge(['meta' => collect(['bio' => '<p onclick="x">Me</p>', 'name' => 'Ann'])]);

        SafeHtml::cleanInput($request, 'meta.bio');

        $this->assertSame(['bio' => '<p>Me</p>', 'name' => 'Ann'], $request->input('meta'));
    }

    /** A rule of the same name would match items the walk can't, which would reach validated() raw. */
    #[DataProvider('unmatchable')]
    public function test_a_name_a_rule_reads_differently_is_refused(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);

        SafeHtml::cleanInput(Request::create('/', 'POST', ['blocks' => [['body' => '<script>a</script>']]]), $field);
    }

    /** @return array<string, array{string}> */
    public static function unmatchable(): array
    {
        return ['top-level *' => ['*.body'], '* inside a level' => ['blocks.*.b*'], '**' => ['blocks.**'], 'escaped dot' => ['blocks\.0'], '{first}' => ['blocks.{first}.body'], '{last}' => ['blocks.{last}.body']];
    }

    public function test_it_uses_the_apps_policy(): void
    {
        $this->app->singleton(SafeHtml::class, fn () => new SafeHtml(SafeHtml::policy()->allowElement('img', ['src'])));
        $request = Request::create('/', 'POST', ['content' => '<img src="https://example.test/a.png" onerror="x">']);

        SafeHtml::cleanInput($request, 'content');

        $this->assertSame('<img src="https://example.test/a.png" />', $request->input('content'));
    }
}

final class AnnouncementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        SafeHtml::cleanInput($this, 'content', 'bio');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['content' => ['nullable', 'string'], 'bio' => ['nullable', 'string']];
    }
}

final class TranslationsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        SafeHtml::cleanInput($this, 'translations.*.content');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['translations.*.content' => ['nullable', 'string']];
    }
}
