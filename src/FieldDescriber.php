<?php

namespace Hawasly\ApiSpec;

use Illuminate\Support\Str;

/**
 * Writes the description a field carries wherever it appears — in form-data,
 * in a JSON schema, in the markdown table on the request.
 *
 * The allowed values of an enum are stated in full and never abbreviated: a
 * field that accepts one of five strings is unusable until the caller knows
 * which five, and that is the single question a generated spec most often
 * fails to answer.
 */
class FieldDescriber
{
    /**
     * One line, for a form-data row or a schema description.
     */
    public function line(array $field): string
    {
        $parts = [$field['required'] ? 'REQUIRED' : 'optional'];

        $type = $this->typeLabel($field);

        if ($type) {
            $parts[] = $type;
        }

        foreach ($this->constraints($field) as $constraint) {
            $parts[] = $constraint;
        }

        $line = implode(' · ', $parts);

        // The allowed values go last and in full, because this is the one
        // thing a caller cannot guess.
        if ($field['in']) {
            $line .= "\nAllowed values: ".$this->values($field['in']);
        }

        if ($note = $this->note($field)) {
            $line .= "\n".$note;
        }

        return $line;
    }

    /**
     * A markdown cell for the table on the request description.
     */
    public function cell(array $field): string
    {
        $notes = $this->constraints($field);

        if ($field['in']) {
            $notes[] = '**one of:** '.$this->values($field['in']);
        }

        if ($note = $this->note($field)) {
            $notes[] = $note;
        }

        return implode(' · ', $notes);
    }

    public function values(array $in): string
    {
        return collect($in)->map(fn ($v) => '`'.$v.'`')->implode(', ');
    }

    private function typeLabel(array $field): ?string
    {
        return match ($field['type']) {
            'file' => 'file upload',
            'image' => 'image upload',
            'integer' => 'integer',
            'number' => 'decimal',
            'boolean' => 'boolean (true / false)',
            'date' => 'date (YYYY-MM-DD)',
            'email' => 'email address',
            'array' => 'array',
            default => null,
        };
    }

    /**
     * @return array<int,string>
     */
    private function constraints(array $field): array
    {
        $out = [];
        $rules = $field['rules'] ?? [];
        $numeric = in_array($field['type'], ['integer', 'number'], true);

        if ($field['min'] !== null) {
            $out[] = $numeric
                ? 'min value '.$field['min']
                : ($field['type'] === 'file' || $field['type'] === 'image'
                    ? 'min '.$field['min'].' KB'
                    : 'min length '.$field['min']);
        }

        if ($field['max'] !== null) {
            $out[] = $numeric
                ? 'max value '.$field['max']
                : ($field['type'] === 'file' || $field['type'] === 'image'
                    ? 'max '.$field['max'].' KB'
                    : 'max length '.$field['max']);
        }

        foreach ($rules as $rule) {
            $head = Str::before($rule, ':');
            $tail = Str::after($rule, ':');

            $out[] = match ($head) {
                'mimes' => 'accepts: '.str_replace(',', ', ', $tail),
                'unique' => 'must be unique',
                'confirmed' => 'send `'.$field['name'].'_confirmation` with the same value',
                'exists' => 'must reference an existing '.Str::headline(Str::before($tail, ',')),
                'url' => 'a full URL',
                'uuid' => 'a UUID',
                'ip' => 'an IP address',
                'json' => 'a JSON string',
                'timezone' => 'a timezone identifier, e.g. Asia/Damascus',
                'date_format' => 'format: '.$tail,
                'after' => 'must be after '.$tail,
                'before' => 'must be before '.$tail,
                'after_or_equal' => 'must be on or after '.$tail,
                'before_or_equal' => 'must be on or before '.$tail,
                'same' => 'must match '.$tail,
                'different' => 'must differ from '.$tail,
                'digits' => 'exactly '.$tail.' digits',
                default => null,
            } ?? '';
        }

        return array_values(array_filter($out));
    }

    /**
     * A conditional requirement, stated rather than left inside the rule
     * string where nobody reads it.
     */
    private function note(array $field): ?string
    {
        foreach ($field['rules'] ?? [] as $rule) {
            if (str_starts_with($rule, 'required_if:')) {
                $args = explode(',', Str::after($rule, 'required_if:'));
                $other = array_shift($args);

                return 'Required when `'.$other.'` is '.collect($args)->map(fn ($v) => '`'.$v.'`')->implode(' or ').'.';
            }

            if (str_starts_with($rule, 'required_with:')) {
                return 'Required when `'.Str::after($rule, 'required_with:').'` is present.';
            }

            if (str_starts_with($rule, 'required_without:')) {
                return 'Required when `'.Str::after($rule, 'required_without:').'` is absent.';
            }

            if (str_starts_with($rule, 'required_unless:')) {
                $args = explode(',', Str::after($rule, 'required_unless:'));
                $other = array_shift($args);

                return 'Required unless `'.$other.'` is '.collect($args)->map(fn ($v) => '`'.$v.'`')->implode(' or ').'.';
            }
        }

        return null;
    }
}
