<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A department's extra questions ("Server name", "Invoice number", "Urgency reason"…) asked when a ticket is filed.
 * Field definitions are validated when an admin saves them; answers are validated against them when a ticket is filed.
 */
class TicketForm
{
    public const TYPES = ['text', 'textarea', 'select', 'number', 'checkbox'];

    public const MAX_FIELDS = 8;

    /** Rules for the admin's field definitions (department create/update). */
    public static function definitionRules(): array
    {
        return [
            'form_fields' => ['nullable', 'array', 'max:'.self::MAX_FIELDS],
            'form_fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'form_fields.*.label' => ['required', 'string', 'max:80'],
            'form_fields.*.type' => ['required', Rule::in(self::TYPES)],
            'form_fields.*.required' => ['sometimes', 'boolean'],
            'form_fields.*.options' => ['nullable', 'array', 'max:20'],
            'form_fields.*.options.*' => ['string', 'max:80'],
        ];
    }

    /** A "select" with no options would be unanswerable. */
    public static function checkDefinitions(array $fields): ?string
    {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'select' && empty($f['options'])) {
                return "The field \"{$f['label']}\" needs at least one option.";
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed> the cleaned answers, keyed by field key (unknown keys are dropped)
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validate(?array $fields, ?array $answers): array
    {
        $fields = $fields ?? [];
        $rules = [];
        $names = [];

        foreach ($fields as $f) {
            $k = $f['key'];
            $required = ! empty($f['required']) && $f['type'] !== 'checkbox';
            $base = $required ? ['required'] : ['nullable'];
            $rules[$k] = match ($f['type']) {
                'number' => [...$base, 'numeric', 'between:-1000000000,1000000000'],
                'checkbox' => ['nullable', 'boolean'],
                'select' => [...$base, 'string', Rule::in($f['options'] ?? [])],
                'textarea' => [...$base, 'string', 'max:2000'],
                default => [...$base, 'string', 'max:255'],
            };
            $names[$k] = $f['label'];
        }

        $data = Validator::make(['custom_fields' => $answers ?? []], collect($rules)->mapWithKeys(fn ($r, $k) => ["custom_fields.{$k}" => $r])->all(), [], collect($names)->mapWithKeys(fn ($l, $k) => ["custom_fields.{$k}" => $l])->all())
            ->validate();

        return array_filter($data['custom_fields'] ?? [], fn ($v) => $v !== null && $v !== '');
    }

    /** For display: [{label, value}] in the form's order, with checkbox as Yes/No. */
    public static function present(?array $fields, ?array $answers): array
    {
        $out = [];
        foreach ($fields ?? [] as $f) {
            if (! array_key_exists($f['key'], $answers ?? [])) {
                continue;
            }
            $v = $answers[$f['key']];
            $out[] = ['label' => $f['label'], 'value' => is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v];
        }

        return $out;
    }
}
