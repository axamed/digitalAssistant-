<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Digital Assistant Knowledge Base for Civil Registration Office,
 * Mogadishu Municipal Government, Banadir Region, Somalia.
 *
 * Provides explanatory guidance, procedure details, fee lookups, district information,
 * and rule clarification. It NEVER modifies, creates, or deletes civil records.
 */
final class AssistantService
{
    /**
     * The knowledge base, built on each call rather than held in a constant: the district
     * article reads the district table, and a constant expression may not call a method.
     */
    private static function knowledgeBase(): array
    {
        return [
        [
            'keywords' => ['birth', 'child', 'hospital', 'mother', 'father', 'photo'],
            'title'    => 'Birth Registration Procedures',
            'answer'   => 'Birth registrations require the child\'s full name, gender, date and place of birth, hospital of birth (free-text field), district in Banadir, and both parents\' full names. An optional child photograph (JPG/PNG, up to 2MB) can be uploaded. The certificate fee is $20.00.',
        ],
        [
            'keywords' => ['death', 'deceased', 'guarantor', 'cause', 'informant'],
            'title'    => 'Death Registration Procedures',
            'answer'   => 'Death registrations record the deceased\'s full name, gender, dates of birth and death, place and district of death, informant details, guarantor\'s full name (mandatory responsible person), and an optional hospital document reference. The certificate fee is configured by the system administrator.',
        ],
        [
            'keywords' => ['marriage', 'husband', 'wife', 'mahr', 'witness', 'dowry'],
            'title'    => 'Marriage Registration Procedures',
            'answer'   => 'Marriage registrations record Husband and Wife details (full name, date of birth, phone number, address), marriage date and place, district, Mahr (dowry amount), and two mandatory witnesses with phone numbers. Official labels are Husband and Wife. The certificate fee is $25.00.',
        ],
        [
            'keywords' => ['divorce', 'court', 'decree', 'separate'],
            'title'    => 'Divorce Registration Procedures',
            'answer'   => 'Divorce registrations document Husband and Wife full names and dates of birth, the original marriage registration number (e.g. MR-2024-0045), divorce date, district, and optional court reference decree. The certificate fee is configured by the system administrator.',
        ],
        [
            'keywords' => ['approval', 'supervisor', 'signature', 'reject', 'correction'],
            'title'    => 'Approval Workflow and Digital Signatures',
            'answer'   => 'Records submitted by officers enter the supervisor queue. A supervisor reviews the record and can approve (requiring a digital signature drawn on-screen), reject (mandatory reason required), or request correction (feedback comments required). Supervisors cannot approve their own records.',
        ],
        [
            'keywords' => ['certificate', 'verify', 'qr', 'token', 'print', 'fee', 'payment'],
            'title'    => 'Certificates, Verification & Payment Rules',
            'answer'   => 'Approved records generate a certificate marked Unpaid. An officer or supervisor must mark the certificate as paid before printing is permitted. Each certificate contains an official QR code linked to verify.php with a secure cryptographic verification token.',
        ],
        [
            'keywords' => ['district', 'banadir', 'mogadishu'],
            'title'    => 'Banadir Districts',
            'answer'   => self::districtAnswer(),
        ],
        [
            'keywords' => ['role', 'permission', 'admin', 'officer'],
            'title'    => 'User Roles & Access Control',
            'answer'   => 'The system has three strictly enforced roles: System Administrator (users, roles, settings, backups, audit logs), Civil Registration Officer (registration, search, draft edits, payment recording), and Supervisor / Manager (approvals with digital signatures, reports, monitoring, revocations).',
        ],
        ];
    }

    /**
     * The district list is read from the database rather than written into the answer, so an
     * office that adds a district gets a correct answer on the next question, and the number
     * in the sentence cannot drift from the dropdowns.
     */
    private static function districtAnswer(): string
    {
        $names = array_column(Database::fetchAll('SELECT name FROM districts ORDER BY name ASC'), 'name');
        if ($names === []) {
            return 'No district is registered in the system yet.';
        }
        $total = count($names);
        $last = array_pop($names);

        return "The system covers all {$total} administrative districts of Banadir Region: "
            . implode(', ', $names) . ' and ' . $last . '.';
    }

    /**
     * Search the digital assistant knowledge base.
     */
    public static function ask(string $query): array
    {
        $q = strtolower(trim($query));
        if ($q === '') {
            return [
                'found' => false,
                'title' => 'Civil Registration Digital Assistant',
                'answer' => 'Please type your question regarding civil registration procedures, fee schedules, district rules, or system guidelines.',
            ];
        }

        $bestMatch = null;
        $maxScore = 0;

        foreach (self::knowledgeBase() as $item) {
            $score = 0;
            foreach ($item['keywords'] as $keyword) {
                if (str_contains($q, $keyword)) {
                    $score += 2;
                }
            }
            if (str_contains(strtolower($item['title']), $q)) {
                $score += 5;
            }
            if ($score > $maxScore) {
                $maxScore = $score;
                $bestMatch = $item;
            }
        }

        if ($bestMatch && $maxScore > 0) {
            return [
                'found'  => true,
                'title'  => $bestMatch['title'],
                'answer' => $bestMatch['answer'],
            ];
        }

        return [
            'found'  => false,
            'title'  => 'Procedure Guidance',
            'answer' => 'I could not find a specific article matching your query. As an assistant, I can explain procedures for Birth, Death, Marriage, and Divorce records, certificate fee requirements, verification, digital signatures, and Banadir district rules.',
        ];
    }
}
