<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/** Age is always calculated from date of birth, never stored. */
final class Age
{
    /** "3 yrs", "5 mo" or "12 days"; null when dates are missing or invalid. */
    public static function between(?string $dob, ?string $on = null): ?string
    {
        if (!$dob) {
            return null;
        }
        try {
            $from = new DateTimeImmutable($dob);
            $to = new DateTimeImmutable($on ?: 'today');
        } catch (\Exception) {
            return null;
        }
        if ($from > $to) {
            return null;
        }
        $d = $from->diff($to);
        if ($d->y >= 1) {
            return $d->y . ($d->y > 1 ? ' yrs' : ' yr');
        }
        if ($d->m >= 1) {
            return $d->m . ' mo';
        }
        return $d->days . ($d->days === 1 ? ' day' : ' days');
    }
}
