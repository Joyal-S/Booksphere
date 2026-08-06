<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Validator
 *
 * A tiny, fluent form validator. Rules are chained on the validator
 * and each rule adds an error message for its field when it fails.
 *
 * Example:
 *
 *     $validator = (new Validator($data))
 *         ->required('email', 'email address')
 *         ->email('email')
 *         ->required('password', 'password')
 *         ->min('password', 8, 'password');
 *
 *     if ($validator->passes()) { ... }
 *
 * Errors are grouped per field so the view can display each error
 * next to its input:
 *
 *     $errors['email'] = ['The email address field is required.']
 *
 * The instance methods return $this so rules can be chained.
 */
final class Validator
{
    /**
     * @var array<string, array<int, string>> Field name -> error messages
     */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data The submitted form values
     */
    public function __construct(private readonly array $data) {}

    /**
     * The field must be present and not empty.
     */
    public function required(string $field, string $label): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->add($field, "The $label field is required.");
        }

        return $this;
    }

    /**
     * The field must be a valid email address.
     */
    public function email(string $field): self
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->add($field, 'Enter a valid email address.');
        }

        return $this;
    }

    /**
     * The field must be at least $min characters long.
     */
    public function min(string $field, int $min, string $label): self
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && mb_strlen($value) < $min) {
            $this->add($field, "The $label must be at least $min characters.");
        }

        return $this;
    }

    /**
     * The field must not exceed $max characters.
     */
    public function max(string $field, int $max, string $label): self
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && mb_strlen($value) > $max) {
            $this->add($field, "The $label must not exceed $max characters.");
        }

        return $this;
    }

    /**
     * The field must contain the same value as $otherField.
     *
     * Used for password confirmation fields: the confirmation must
     * equal the password.
     */
    public function same(string $field, string $otherField, string $label): self
    {
        $first  = $this->data[$field] ?? null;
        $second = $this->data[$otherField] ?? null;

        if ($first !== $second) {
            $this->add($field, "The $label do not match.");
        }

        return $this;
    }

    /**
     * The field must be a whole number (integer).
     *
     * Optional fields pass through untouched: like email(), the
     * rule only fires when the field carries a value.
     */
    public function integer(string $field, string $label): self
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->add($field, "The $label must be a whole number.");
        }

        return $this;
    }

    /**
     * The field must be a whole number between $min and $max.
     *
     * Used for numeric ranges such as publication years
     * (1000..current year). Optional fields pass through untouched.
     */
    public function between(string $field, int $min, int $max, string $label): self
    {
        $value = $this->data[$field] ?? null;

        if (
            is_string($value) && $value !== ''
            && (filter_var($value, FILTER_VALIDATE_INT) === false
                || (int) $value < $min || (int) $value > $max)
        ) {
            $this->add($field, "The $label must be a whole number between $min and $max.");
        }

        return $this;
    }

    /**
     * The field must be one of a fixed list of allowed values.
     *
     * Used for whitelisted choices such as book status or language
     * codes - anything a <select> can contain. Rejecting unknown
     * values keeps junk out of the database.
     *
     * @param array<int, string> $allowed The accepted values
     */
    public function in(string $field, array $allowed, string $label): self
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value) && !in_array($value, $allowed, true)) {
            $this->add($field, "The chosen $label is not valid.");
        }

        return $this;
    }

    /**
     * Attach a custom error message to a field.
     *
     * Additive escape hatch for rules that need logic beyond the
     * declarative table (e.g. an ISBN checksum). The Google Books
     * search request composes it into the same validator so every
     * field error travels in one map.
     */
    public function error(string $field, string $message): self
    {
        $this->add($field, $message);

        return $this;
    }

    /**
     * Whether every rule passed.
     */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * Return the collected error messages, grouped by field.
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
