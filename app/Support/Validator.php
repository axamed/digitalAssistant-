<?php
declare(strict_types=1);

namespace App\Support;

/** Small server-side validator. Frontend checks are a convenience; these are mandatory. */
final class Validator
{
    private array $errors = [];

    private function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    private function value(string $field): string
    {
        return trim((string) ($this->data[$field] ?? ''));
    }

    private function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function required(string $field, string $label): self
    {
        return $this->value($field) === '' ? $this->fail($field, "$label is required.") : $this;
    }

    public function maxLength(string $field, string $label, int $max): self
    {
        return mb_strlen($this->value($field)) > $max ? $this->fail($field, "$label must be at most $max characters.") : $this;
    }

    public function date(string $field, string $label): self
    {
        $v = $this->value($field);
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        return ($v !== '' && (!$d || $d->format('Y-m-d') !== $v)) ? $this->fail($field, "$label is not a valid date.") : $this;
    }

    public function notFuture(string $field, string $label): self
    {
        $v = $this->value($field);
        return ($v !== '' && $v > date('Y-m-d')) ? $this->fail($field, "$label cannot be in the future.") : $this;
    }

    /** $field must be before $other (both Y-m-d). */
    public function before(string $field, string $label, string $other, string $otherLabel): self
    {
        $a = $this->value($field);
        $b = $this->value($other);
        return ($a !== '' && $b !== '' && $a >= $b) ? $this->fail($field, "$label must be before $otherLabel.") : $this;
    }

    /** $field must be on or after $other (both Y-m-d). */
    public function onOrAfter(string $field, string $label, string $other, string $otherLabel): self
    {
        $a = $this->value($field);
        $b = $this->value($other);
        return ($a !== '' && $b !== '' && $a < $b) ? $this->fail($field, "$label cannot be earlier than $otherLabel.") : $this;
    }

    public function phone(string $field, string $label): self
    {
        $v = $this->value($field);
        return ($v !== '' && !preg_match('/^\+?[0-9 ]{7,15}$/', $v)) ? $this->fail($field, "$label is not a valid phone number.") : $this;
    }

    public function in(string $field, string $label, array $allowed): self
    {
        $v = $this->value($field);
        return ($v !== '' && !in_array($v, $allowed, true)) ? $this->fail($field, "$label has an invalid value.") : $this;
    }

    public function numeric(string $field, string $label): self
    {
        $v = $this->value($field);
        return ($v !== '' && !is_numeric($v)) ? $this->fail($field, "$label must be a valid number.") : $this;
    }

    /** Numeric lower bound, e.g. a fee of at least 0. */
    public function min(string $field, string $label, float $min): self
    {
        $v = $this->value($field);
        return ($v !== '' && is_numeric($v) && (float) $v < $min)
            ? $this->fail($field, "$label must be at least " . rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.') . '.')
            : $this;
    }

    /** Email address, validated only when a value is present. */
    public function email(string $field, string $label): self
    {
        $v = $this->value($field);
        return ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL))
            ? $this->fail($field, "$label must be a valid email address.")
            : $this;
    }

    /** Two fields must hold the same value, e.g. a password confirmation. */
    public function matches(string $field, string $other, string $message): self
    {
        return $this->value($field) === $this->value($other) ? $this : $this->fail($field, $message);
    }

    public function custom(string $field, string $message): self
    {
        return $this->fail($field, $message);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
