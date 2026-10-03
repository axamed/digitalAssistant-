<?php
/**
 * Profile photo, or the initials of the name when there is no photo.
 *
 * $path   stored photo path (null when none)
 * $name   full name, used for the initials
 * $size   pixel size of the square
 * $class  extra CSS classes
 */
declare(strict_types=1);

$photoPath = isset($path) && is_string($path) && trim($path) !== '' ? $path : null;
$stamp = $photoPath !== null ? avatar_photo_stamp($photoPath) : null;
$size = (int) ($size ?? 40);
$extraClass = trim((string) ($class ?? ''));
?>
<?php if ($photoPath !== null && $stamp !== null): ?>
  <img class="profile-photo <?= e($extraClass) ?>"
       src="<?= e(url('avatar.php?v=' . $stamp)) ?>"
       alt="Profile photo" width="<?= $size ?>" height="<?= $size ?>"
       style="width:<?= $size ?>px; height:<?= $size ?>px;">
<?php else: ?>
  <span class="avatar-initials <?= e($extraClass) ?>"
        style="width:<?= $size ?>px; height:<?= $size ?>px; font-size:<?= max(10, (int) round($size * 0.4)) ?>px;"
        aria-hidden="true"><?= e(initials((string) ($name ?? ''))) ?></span>
<?php endif; ?>
