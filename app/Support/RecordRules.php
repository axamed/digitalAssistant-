<?php
declare(strict_types=1);

namespace App\Support;

use App\Services\RecordService;

/**
 * The single validation rule set for the four record types.
 * Used by the officer registration pages and by records/edit.php so a record
 * can never pass creation but fail an update (or the other way round).
 */
final class RecordRules
{
    /**
     * Build the validator for a record type.
     *
     * @param array       $data       submitted values
     * @param string|null $confirmDup set to a truthy value to accept a detected duplicate
     * @param int|null    $excludeId  record being edited (excluded from the duplicate check)
     */
    public static function make(string $type, array $data, $confirmDup = false, ?int $excludeId = null): Validator
    {
        $fields = RecordTypes::fields($type);

        $v = Validator::make($data);

        // Generic rules driven by the field definitions.
        foreach ($fields as $name => $field) {
            $label = $field['label'];
            if (!empty($field['required'])) {
                $v->required($name, $label);
            }
            if (($field['type'] ?? '') === 'date') {
                $v->date($name, $label);
                $v->notFuture($name, $label);
            }
            if (($field['type'] ?? '') === 'gender') {
                $v->in($name, $label, RecordTypes::GENDERS);
            }
            if (($field['type'] ?? '') === 'marital') {
                $v->in($name, $label, RecordTypes::MARITAL_STATUSES);
            }
            if (($field['type'] ?? '') === 'district') {
                $v->numeric($name, $label);
            }
            if (($field['type'] ?? '') === 'phone') {
                $v->phone($name, $label);
            }
            if (!empty($field['max'])) {
                $v->maxLength($name, $label, (int) $field['max']);
            }
        }

        // Cross-field date rules.
        match ($type) {
            'death'    => $v->before('date_of_birth', 'Date of birth', 'date_of_death', 'date of death'),
            'marriage' => $v
                ->before('husband_date_of_birth', "Husband's date of birth", 'marriage_date', 'marriage date')
                ->before('wife_date_of_birth', "Wife's date of birth", 'marriage_date', 'marriage date'),
            'divorce'  => $v
                ->before('husband_date_of_birth', "Husband's date of birth", 'divorce_date', 'divorce date')
                ->before('wife_date_of_birth', "Wife's date of birth", 'divorce_date', 'divorce date'),
            default    => null,
        };

        // Registration date may never be before the event it registers.
        match ($type) {
            'birth'    => $v->onOrAfter('registration_date', 'Registration date', 'date_of_birth', 'date of birth'),
            'death'    => $v->onOrAfter('registration_date', 'Registration date', 'date_of_death', 'date of death'),
            'marriage' => $v->onOrAfter('registration_date', 'Registration date', 'marriage_date', 'marriage date'),
            'divorce'  => $v->onOrAfter('registration_date', 'Registration date', 'divorce_date', 'divorce date'),
            default    => null,
        };

        // Duplicate prevention.
        if (!$confirmDup) {
            $duplicate = self::duplicateOf($type, $data, $excludeId);
            if ($duplicate !== null) {
                $v->custom(self::duplicateField($type), 'Potential duplicate detected.');
            }
        }

        return $v;
    }

    /** Field the duplicate error is attached to. */
    public static function duplicateField(string $type): string
    {
        // A divorce is identified by the marriage it dissolves, not by a person's name.
        return $type === 'divorce' ? 'marriage_registration_number' : RecordTypes::ALL[$type]['name'];
    }

    /**
     * Look for a possible duplicate of the submitted data.
     * Returns the matching record (id, registration_number, ...) or null.
     */
    public static function duplicateOf(string $type, array $data, ?int $excludeId = null): ?array
    {
        $primary = trim((string) ($data[RecordTypes::ALL[$type]['name']] ?? ''));
        if ($primary === '') {
            return null;
        }

        if ($type === 'divorce') {
            $marriageRef = trim((string) ($data['marriage_registration_number'] ?? ''));
            if ($marriageRef === '') {
                return null;
            }
            return RecordService::checkDuplicateByMarriageReference($marriageRef, $excludeId);
        }

        $dateField = self::duplicateDateField($type);
        $date = trim((string) ($data[$dateField] ?? ''));
        if ($date === '') {
            return null;
        }

        return RecordService::checkDuplicate($type, $primary, $date, $excludeId);
    }

    private static function duplicateDateField(string $type): string
    {
        return match ($type) {
            'birth'    => 'date_of_birth',
            'death'    => 'date_of_death',
            'marriage' => 'marriage_date',
            default    => 'divorce_date',
        };
    }
}
