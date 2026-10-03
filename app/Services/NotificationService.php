<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Database};

/**
 * Service managing user notifications and unread badge counts.
 */
final class NotificationService
{
    /**
     * Send an in-app notification to a user.
     */
    public static function send(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): int
    {
        Database::execute(
            'INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())',
            [$userId, $title, $message, $type, $link]
        );
        return Database::lastId();
    }

    /**
     * Get unread notification count for current user.
     */
    public static function unreadCount(?int $userId = null): int
    {
        $userId ??= (int) (Auth::id() ?? 0);
        if ($userId === 0) {
            return 0;
        }
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    /**
     * Get recent notifications for a user.
     */
    public static function forUser(?int $userId = null, int $limit = 20): array
    {
        $userId ??= (int) (Auth::id() ?? 0);
        if ($userId === 0) {
            return [];
        }
        return Database::fetchAll(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    /**
     * Mark a notification as read.
     */
    public static function markAsRead(int $id, ?int $userId = null): bool
    {
        $userId ??= (int) (Auth::id() ?? 0);
        return Database::execute(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /**
     * Mark all notifications as read for current user.
     */
    public static function markAllAsRead(?int $userId = null): bool
    {
        $userId ??= (int) (Auth::id() ?? 0);
        if ($userId === 0) {
            return false;
        }
        Database::execute('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$userId]);
        return true;
    }

    /**
     * Delete every notification a user has already read. Unread items are kept.
     */
    public static function clearRead(?int $userId = null): int
    {
        $userId ??= (int) (Auth::id() ?? 0);
        if ($userId === 0) {
            return 0;
        }
        return Database::execute('DELETE FROM notifications WHERE user_id = ? AND is_read = 1', [$userId]);
    }
}
