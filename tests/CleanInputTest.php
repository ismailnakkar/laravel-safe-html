<?php

declare(strict_types=1);

namespace SafeHtml\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
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

    public function test_a_wildcard_is_refused_rather_than_left_raw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SafeHtml::cleanInput(Request::create('/', 'POST', ['blocks' => [['body' => '<script>a</script>']]]), 'blocks.*.body');
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
