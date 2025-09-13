<?php
// includes/UserPhotoService.php - User photo management service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class UserPhotoService {

    public static function uploadUserPhoto(int $userId, array $uploadedFile, bool $setPrimary = false): array {
        // Validate file
        $validationErrors = self::validatePhoto($uploadedFile);
        if (!empty($validationErrors)) {
            throw new Exception(implode(', ', $validationErrors));
        }

        // Create user photos directory
        $userPhotosDir = __DIR__ . '/../user_photos';
        if (!is_dir($userPhotosDir)) {
            @mkdir($userPhotosDir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
        $filename = uniqid('user_' . $userId . '_', true) . '.' . strtolower($extension);
        $filePath = $userPhotosDir . '/' . $filename;

        // Move uploaded file (or copy if it's not from direct upload)
        $success = false;
        if (is_uploaded_file($uploadedFile['tmp_name'])) {
            // Direct upload - use move_uploaded_file
            $success = move_uploaded_file($uploadedFile['tmp_name'], $filePath);
        } else {
            // File is already processed - use copy instead
            $success = copy($uploadedFile['tmp_name'], $filePath);
        }

        if (!$success) {
            throw new Exception('Failed to save photo');
        }

        // Set permissions
        @chmod($filePath, 0644);

        $pdo = Database::getInstance();

        // If setting as primary, remove primary flag from other photos
        if ($setPrimary) {
            $pdo->prepare('UPDATE user_photos SET is_primary = 0 WHERE user_id = ?')
                ->execute([$userId]);
        }

        // Save photo record
        $stmt = $pdo->prepare('
            INSERT INTO user_photos (user_id, filename, original_name, file_path, file_size, mime_type, is_primary)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $filename,
            $uploadedFile['name'],
            $filePath,
            $uploadedFile['size'],
            $uploadedFile['type'],
            $setPrimary ? 1 : 0
        ]);

        $photoId = $pdo->lastInsertId();

        Logger::info('UserPhotoService - Photo uploaded', [
            'photo_id' => $photoId,
            'user_id' => $userId,
            'filename' => $filename,
            'is_primary' => $setPrimary
        ]);

        return [
            'id' => $photoId,
            'filename' => $filename,
            'original_name' => $uploadedFile['name'],
            'file_size' => $uploadedFile['size'],
            'is_primary' => $setPrimary
        ];
    }

    public static function getUserPhotos(int $userId): array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('
            SELECT id, filename, original_name, file_size, mime_type, is_primary, created_at
            FROM user_photos
            WHERE user_id = ?
            ORDER BY is_primary DESC, created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPrimaryPhoto(int $userId): ?array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('
            SELECT id, filename, original_name, file_path, file_size, mime_type, created_at
            FROM user_photos
            WHERE user_id = ? AND is_primary = 1
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        return $photo ?: null;
    }

    public static function getPhotoById(int $photoId, int $userId): ?array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('
            SELECT id, filename, original_name, file_path, file_size, mime_type, is_primary, created_at
            FROM user_photos
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([$photoId, $userId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        return $photo ?: null;
    }

    public static function setPrimaryPhoto(int $photoId, int $userId): bool {
        $pdo = Database::getInstance();

        // First check if photo exists and belongs to user
        $photo = self::getPhotoById($photoId, $userId);
        if (!$photo) {
            return false;
        }

        // Remove primary flag from all user photos
        $pdo->prepare('UPDATE user_photos SET is_primary = 0 WHERE user_id = ?')
            ->execute([$userId]);

        // Set this photo as primary
        $stmt = $pdo->prepare('UPDATE user_photos SET is_primary = 1 WHERE id = ? AND user_id = ?');
        $result = $stmt->execute([$photoId, $userId]);

        if ($result) {
            Logger::info('UserPhotoService - Primary photo updated', [
                'photo_id' => $photoId,
                'user_id' => $userId
            ]);
        }

        return $result;
    }

    public static function deletePhoto(int $photoId, int $userId): bool {
        $pdo = Database::getInstance();

        // Get photo info
        $photo = self::getPhotoById($photoId, $userId);
        if (!$photo) {
            return false;
        }

        // Delete file
        if (file_exists($photo['file_path'])) {
            @unlink($photo['file_path']);
        }

        // Delete database record
        $stmt = $pdo->prepare('DELETE FROM user_photos WHERE id = ? AND user_id = ?');
        $result = $stmt->execute([$photoId, $userId]);

        if ($result) {
            Logger::info('UserPhotoService - Photo deleted', [
                'photo_id' => $photoId,
                'user_id' => $userId,
                'filename' => $photo['filename']
            ]);

            // If this was the primary photo, set another as primary if available
            if ($photo['is_primary']) {
                $remainingPhotos = self::getUserPhotos($userId);
                if (!empty($remainingPhotos)) {
                    self::setPrimaryPhoto($remainingPhotos[0]['id'], $userId);
                }
            }
        }

        return $result;
    }

    public static function getPhotoUrl(string $filename): string {
        return '/user_photos/' . $filename;
    }

    public static function getPhotoPath(string $filename): string {
        return __DIR__ . '/../user_photos/' . $filename;
    }

    private static function validatePhoto(array $uploadedFile): array {
        $errors = [];
        $maxFileSize = Config::get('max_file_size', 10 * 1024 * 1024); // 10MB default

        if (empty($uploadedFile['tmp_name'])) {
            $errors[] = 'No photo uploaded';
            return $errors;
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed';
            return $errors;
        }

        if ($uploadedFile['size'] > $maxFileSize) {
            $errors[] = 'Photo is too large (max 10MB)';
        }

        if (!self::isValidImageType($uploadedFile['type'])) {
            $errors[] = 'Invalid photo format. Please use JPEG, PNG, or WebP';
        }

        // Check if file is actually an image
        $imageInfo = @getimagesize($uploadedFile['tmp_name']);
        if (!$imageInfo) {
            $errors[] = 'Invalid image file';
        }

        return $errors;
    }

    private static function isValidImageType(string $mimeType): bool {
        return in_array($mimeType, [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp'
        ]);
    }

    public static function getUserPhotoCount(int $userId): int {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_photos WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function cleanupOrphanedPhotos(): int {
        $pdo = Database::getInstance();

        // Get photos that don't belong to existing users
        $stmt = $pdo->prepare('
            SELECT up.id, up.file_path
            FROM user_photos up
            LEFT JOIN users u ON up.user_id = u.id
            WHERE u.id IS NULL
        ');
        $stmt->execute();
        $orphanedPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deleted = 0;
        foreach ($orphanedPhotos as $photo) {
            // Delete file
            if (file_exists($photo['file_path'])) {
                @unlink($photo['file_path']);
            }

            // Delete database record
            $pdo->prepare('DELETE FROM user_photos WHERE id = ?')->execute([$photo['id']]);
            $deleted++;
        }

        if ($deleted > 0) {
            Logger::info('UserPhotoService - Cleaned up orphaned photos', ['count' => $deleted]);
        }

        return $deleted;
    }
}