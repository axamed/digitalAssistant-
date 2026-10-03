<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the four record types:
 * table names (SQL whitelist), number prefixes, primary name column,
 * and the field layout used to display and edit a record.
 *
 * Field spec keys:
 *   name     column name in the record table
 *   label    human readable label
 *   type     text | textarea | gender | marital | date | time | district | phone
 *   required must not be empty
 *   max      maximum length (text/textarea only)
 *   hint     short help text shown under the input
 *   age_asof 'today' or the name of another date field; shows a live age
 *   options  allowed values (gender / marital)
 */
final class RecordTypes
{
    public const ALL = [
        'birth'    => ['table' => 'birth_records',    'prefix' => 'BR', 'label' => 'Birth',    'name' => 'child_full_name', 'gender' => true],
        'death'    => ['table' => 'death_records',    'prefix' => 'DR', 'label' => 'Death',    'name' => 'deceased_full_name', 'gender' => true],
        'marriage' => ['table' => 'marriage_records', 'prefix' => 'MR', 'label' => 'Marriage', 'name' => 'husband_full_name', 'gender' => false],
        'divorce'  => ['table' => 'divorce_records',  'prefix' => 'DV', 'label' => 'Divorce',  'name' => 'husband_full_name', 'gender' => false],
    ];

    /**
     * The other party of a couple, where the event has one. Marriage and divorce are
     * recorded for two people and have no gender column of their own, so a report that
     * lists every event needs the counterpart's name to stay readable.
     */
    public const PARTNER = [
        'marriage' => 'wife_full_name',
        'divorce'  => 'wife_full_name',
    ];

    public const STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'correction_required'];

    public const GENDERS = ['female', 'male'];

    public const MARITAL_STATUSES = ['single', 'married', 'divorced', 'widowed'];

    public static function table(string $type): string
    {
        return self::ALL[$type]['table'] ?? throw new \InvalidArgumentException('Unknown record type');
    }

    public static function exists(string $type): bool
    {
        return isset(self::ALL[$type]);
    }

    /** True when the event type's table has a gender column (birth and death only). */
    public static function hasGender(string $type): bool
    {
        return (bool) (self::ALL[$type]['gender'] ?? false);
    }

    /** Field groups of a record type, used to render details and edit forms. */
    public static function groups(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unknown record type');
    }

    /** Flat list of every editable field of a record type. */
    public static function fields(string $type): array
    {
        $flat = [];
        foreach (self::groups($type) as $group) {
            foreach ($group['fields'] as $field) {
                $flat[$field['name']] = $field;
            }
        }
        return $flat;
    }

    private const FIELDS = [
        'birth' => [
            ['title' => 'Child Information', 'fields' => [
                ['name' => 'child_full_name', 'label' => "Child's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'gender', 'label' => 'Gender', 'type' => 'gender', 'required' => true],
                ['name' => 'date_of_birth', 'label' => 'Date of Birth', 'type' => 'date', 'required' => true, 'age_asof' => 'today'],
                ['name' => 'time_of_birth', 'label' => 'Time of Birth', 'type' => 'time'],
                ['name' => 'district_id', 'label' => 'District', 'type' => 'district', 'required' => true],
                ['name' => 'place_of_birth', 'label' => 'Place of Birth', 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'hospital_of_birth', 'label' => 'Hospital of Birth', 'type' => 'text', 'required' => true, 'max' => 120,
                 'hint' => 'Free-text hospital or clinic name as indicated on the medical birth notification.'],
            ]],
            ['title' => "Father's Particulars", 'fields' => [
                ['name' => 'father_full_name', 'label' => "Father's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'father_nationality', 'label' => "Father's Nationality", 'type' => 'text', 'max' => 60],
                ['name' => 'father_reference', 'label' => "Father's ID / Reference", 'type' => 'text', 'max' => 60],
                ['name' => 'father_phone', 'label' => "Father's Phone", 'type' => 'phone'],
            ]],
            ['title' => "Mother's Particulars", 'fields' => [
                ['name' => 'mother_full_name', 'label' => "Mother's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'mother_nationality', 'label' => "Mother's Nationality", 'type' => 'text', 'max' => 60],
                ['name' => 'mother_reference', 'label' => "Mother's ID / Reference", 'type' => 'text', 'max' => 60],
                ['name' => 'mother_phone', 'label' => "Mother's Phone", 'type' => 'phone'],
            ]],
            ['title' => 'Registration Administration', 'fields' => [
                ['name' => 'registration_date', 'label' => 'Registration Date', 'type' => 'date', 'required' => true],
                ['name' => 'notes', 'label' => 'Administrative Notes', 'type' => 'textarea'],
            ]],
        ],

        'death' => [
            ['title' => 'Deceased Particulars', 'fields' => [
                ['name' => 'deceased_full_name', 'label' => "Deceased Person's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'gender', 'label' => 'Gender', 'type' => 'gender', 'required' => true],
                ['name' => 'date_of_birth', 'label' => 'Date of Birth', 'type' => 'date', 'required' => true, 'age_asof' => 'date_of_death'],
                ['name' => 'date_of_death', 'label' => 'Date of Death', 'type' => 'date', 'required' => true],
                ['name' => 'time_of_death', 'label' => 'Time of Death', 'type' => 'time'],
                ['name' => 'place_of_death', 'label' => 'Place of Death', 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'district_id', 'label' => 'District', 'type' => 'district', 'required' => true],
                ['name' => 'cause_of_death', 'label' => 'Cause of Death', 'type' => 'textarea'],
                ['name' => 'marital_status', 'label' => 'Marital Status', 'type' => 'marital'],
                ['name' => 'spouse_name', 'label' => "Spouse's Full Name", 'type' => 'text', 'max' => 150],
                ['name' => 'nationality', 'label' => 'Nationality', 'type' => 'text', 'max' => 60],
                ['name' => 'reference_number', 'label' => 'Reference Number', 'type' => 'text', 'max' => 60],
                ['name' => 'hospital_document_reference', 'label' => 'Hospital Document Reference', 'type' => 'text', 'max' => 100],
            ]],
            ['title' => 'Guarantor & Informant', 'fields' => [
                ['name' => 'guarantor_full_name', 'label' => 'Guarantor Full Name (responsible person)', 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'informant_name', 'label' => "Informant's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'informant_relationship', 'label' => "Informant's Relationship", 'type' => 'text', 'max' => 60],
                ['name' => 'informant_phone', 'label' => "Informant's Phone", 'type' => 'phone'],
            ]],
            ['title' => 'Registration Administration', 'fields' => [
                ['name' => 'registration_date', 'label' => 'Registration Date', 'type' => 'date', 'required' => true],
                ['name' => 'notes', 'label' => 'Administrative Notes', 'type' => 'textarea'],
            ]],
        ],

        'marriage' => [
            ['title' => 'Husband', 'fields' => [
                ['name' => 'husband_full_name', 'label' => "Husband's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'husband_date_of_birth', 'label' => "Husband's Date of Birth", 'type' => 'date', 'required' => true, 'age_asof' => 'marriage_date'],
                ['name' => 'husband_nationality', 'label' => "Husband's Nationality", 'type' => 'text', 'max' => 60],
                ['name' => 'husband_reference', 'label' => "Husband's ID / Reference", 'type' => 'text', 'max' => 60],
                ['name' => 'husband_phone', 'label' => "Husband's Phone", 'type' => 'phone', 'required' => true],
                ['name' => 'husband_address', 'label' => "Husband's Address", 'type' => 'text', 'max' => 200],
            ]],
            ['title' => 'Wife', 'fields' => [
                ['name' => 'wife_full_name', 'label' => "Wife's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'wife_date_of_birth', 'label' => "Wife's Date of Birth", 'type' => 'date', 'required' => true, 'age_asof' => 'marriage_date'],
                ['name' => 'wife_nationality', 'label' => "Wife's Nationality", 'type' => 'text', 'max' => 60],
                ['name' => 'wife_reference', 'label' => "Wife's ID / Reference", 'type' => 'text', 'max' => 60],
                ['name' => 'wife_phone', 'label' => "Wife's Phone", 'type' => 'phone', 'required' => true],
                ['name' => 'wife_address', 'label' => "Wife's Address", 'type' => 'text', 'max' => 200],
            ]],
            ['title' => 'Marriage Particulars', 'fields' => [
                ['name' => 'marriage_date', 'label' => 'Marriage Date', 'type' => 'date', 'required' => true],
                ['name' => 'place_of_marriage', 'label' => 'Place of Marriage', 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'district_id', 'label' => 'District', 'type' => 'district', 'required' => true],
                ['name' => 'mahr_amount', 'label' => 'Mahr (Dowry)', 'type' => 'text', 'required' => true, 'max' => 100,
                 'hint' => 'As declared by the parties, e.g. "500 USD" or "20 Camels".'],
            ]],
            ['title' => 'Witnesses', 'fields' => [
                ['name' => 'witness1_full_name', 'label' => "First Witness's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'witness1_phone', 'label' => "First Witness's Phone", 'type' => 'phone', 'required' => true],
                ['name' => 'witness2_full_name', 'label' => "Second Witness's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'witness2_phone', 'label' => "Second Witness's Phone", 'type' => 'phone', 'required' => true],
            ]],
            ['title' => 'Registration Administration', 'fields' => [
                ['name' => 'registration_date', 'label' => 'Registration Date', 'type' => 'date', 'required' => true],
                ['name' => 'notes', 'label' => 'Administrative Notes', 'type' => 'textarea'],
            ]],
        ],

        'divorce' => [
            ['title' => 'Original Marriage', 'fields' => [
                ['name' => 'marriage_registration_number', 'label' => 'Original Marriage Registration Number', 'type' => 'text', 'required' => true, 'max' => 30],
            ]],
            ['title' => 'Husband & Wife', 'fields' => [
                ['name' => 'husband_full_name', 'label' => "Husband's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'husband_date_of_birth', 'label' => "Husband's Date of Birth", 'type' => 'date', 'required' => true, 'age_asof' => 'divorce_date'],
                ['name' => 'wife_full_name', 'label' => "Wife's Full Name", 'type' => 'text', 'required' => true, 'max' => 150],
                ['name' => 'wife_date_of_birth', 'label' => "Wife's Date of Birth", 'type' => 'date', 'required' => true, 'age_asof' => 'divorce_date'],
            ]],
            ['title' => 'Dissolution Particulars', 'fields' => [
                ['name' => 'divorce_date', 'label' => 'Divorce Date', 'type' => 'date', 'required' => true],
                ['name' => 'district_id', 'label' => 'District', 'type' => 'district', 'required' => true],
                ['name' => 'divorce_reason', 'label' => 'Reason for Divorce', 'type' => 'text', 'max' => 200],
                ['name' => 'court_reference', 'label' => 'Court Reference', 'type' => 'text', 'max' => 100],
            ]],
            ['title' => 'Registration Administration', 'fields' => [
                ['name' => 'registration_date', 'label' => 'Registration Date', 'type' => 'date', 'required' => true],
                ['name' => 'notes', 'label' => 'Administrative Notes', 'type' => 'textarea'],
            ]],
        ],
    ];
}
