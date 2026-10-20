<?php

declare(strict_types=1);

namespace SafeHtml\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Like max:, but an entity counts as one character: the sanitizer stores ' as &#039;, which max: counts as six. */
final readonly class MaxHtmlLength implements ValidationRule
{
    public function __construct(private int $max) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && mb_strlen(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) > $this->max) {
            $fail('validation.max.string')->translate(['max' => $this->max]);
        }
    }
}
