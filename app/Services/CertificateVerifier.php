<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Database};
use App\Support\RecordTypes;

/** Public verification: returns limited, non-sensitive information only. */
final class CertificateVerifier
{
    /** @return array{result:string, certificate?:array} result = valid | revoked | not_found */
    public static function verify(string $token): array
    {
        $token = substr(preg_replace('/[^A-Za-z0-9-]/', '', $token) ?? '', 0, 64);
        $cert = $token === '' ? null : Database::fetchOne(
            'SELECT id, certificate_number, record_type, record_id, issue_date, status FROM certificates WHERE verification_token = ?',
            [$token]
        );

        if ($cert === null) {
            self::record(null, $token, 'not_found');
            return ['result' => 'not_found'];
        }
        $reg = Database::scalar(
            'SELECT registration_number FROM ' . RecordTypes::table($cert['record_type']) . ' WHERE id = ?',
            [$cert['record_id']]
        );
        $result = $cert['status'] === 'valid' ? 'valid' : 'revoked';
        self::record((int) $cert['id'], $token, $result);

        return ['result' => $result, 'certificate' => [
            'type'         => RecordTypes::ALL[$cert['record_type']]['label'],
            'number'       => $cert['certificate_number'],
            'registration' => $reg,
            'issue_date'   => $cert['issue_date'],
            'issued_by'    => 'Civil Registration Office, Mogadishu',
        ]];
    }

    private static function record(?int $certId, string $token, string $result): void
    {
        Database::execute(
            'INSERT INTO certificate_verifications (certificate_id, token_prefix, result, ip_address) VALUES (?,?,?,?)',
            [$certId, substr($token, 0, 8), $result, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]
        );
        Audit::log('verify_certificate', 'certificates', 'Public verification: ' . $result, null, $certId);
    }
}
