<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Rules;

use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A message must contain something besides empty paragraphs.
 */
class HasMessageContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || MessageBody::isBlank(MessageBody::sanitize($value))) {
            $fail(__('alfakit-helpdesk::helpdesk.errors.empty'));
        }
    }
}
