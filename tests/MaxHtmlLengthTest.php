<?php

declare(strict_types=1);

namespace SafeHtml\Tests;

use Illuminate\Support\Facades\Validator;
use Orchestra\Testbench\TestCase;
use SafeHtml\Rules\MaxHtmlLength;
use SafeHtml\SafeHtml;

final class MaxHtmlLengthTest extends TestCase
{
    private function errors(mixed $content, array $rules = ['nullable', 'string', new MaxHtmlLength(10_000)]): array
    {
        return Validator::make(['content' => $content], ['content' => $rules])->errors()->get('content');
    }

    /** 8,302 characters as typed; stored with each apostrophe as &#039;, 11,857. */
    public function test_it_counts_the_text_as_typed_not_its_encoded_form(): void
    {
        $stored = (new SafeHtml)->clean('<p>' . str_repeat("L'équipe s'est réunie aujourd'hui. ", 237) . '</p>');

        $this->assertSame([], $this->errors($stored));
        $this->assertNotSame([], $this->errors($stored, ['string', 'max:10000']));
    }

    public function test_past_the_cap_it_fails_with_laravels_max_message(): void
    {
        $this->assertSame(
            ['The content field must not be greater than 10000 characters.'],
            $this->errors('<p>' . str_repeat('a', 10_000) . '</p>'),
        );
    }

    public function test_it_counts_characters_not_bytes(): void
    {
        $this->assertSame([], $this->errors(str_repeat('é', 10_000)));
        $this->assertCount(1, $this->errors(str_repeat('é', 10_001)));
    }

    public function test_a_non_string_is_left_to_the_string_rule(): void
    {
        $this->assertSame([], $this->errors(['x'], [new MaxHtmlLength(1)]));
    }
}
