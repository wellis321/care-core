<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\DomainException;
use App\Core\Uuid;

/** Private image storage (photos and signatures). Files live outside the web root and are only served after a permission check. */
final class FileStore
{
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public static function root(): string
    {
        return ROOT_PATH . '/storage/uploads';
    }

    /** @return string path relative to the store */
    public static function saveBytes(int $orgId, string $kind, string $bytes, int $maxBytes): string
    {
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new DomainException('That picture is too large. The limit is ' . ($maxBytes >= 1048576 ? round($maxBytes / 1048576, 1) . ' MB' : (int) ($maxBytes / 1024) . ' KB') . '.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        $info = @getimagesizefromstring($bytes);
        if (!isset(self::TYPES[$mime]) || !$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000) {
            throw new DomainException('Only PNG, JPEG, WebP or GIF pictures can be used.');
        }
        $dir = self::root() . '/' . $orgId . '/' . $kind;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new DomainException('The picture could not be saved. Please try again.');
        }
        $rel = $orgId . '/' . $kind . '/' . Uuid::v4() . '.' . self::TYPES[$mime];
        if (file_put_contents(self::root() . '/' . $rel, $bytes) === false) {
            throw new DomainException('The picture could not be saved. Please try again.');
        }
        return $rel;
    }

    /** A drawn signature arrives as a PNG data URL from the signature pad. */
    public static function saveDataUrl(int $orgId, string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new DomainException('Draw your signature in the box first.');
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || !str_starts_with($bytes, "\x89PNG")) {
            throw new DomainException('That signature could not be read. Please draw it again.');
        }
        return self::saveBytes($orgId, 'signature', $bytes, 400 * 1024);
    }

    /** @return array{0:string,1:string}|null bytes and mime */
    public static function read(?string $rel): ?array
    {
        $path = self::resolve($rel);
        if (!$path || !is_file($path)) {
            return null;
        }
        $bytes = (string) file_get_contents($path);
        return [$bytes, (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'];
    }

    public static function delete(?string $rel): void
    {
        $path = self::resolve($rel);
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    /** Only paths this class created: org/kind/uuid.ext. Anything else (dots, slashes) is refused. */
    private static function resolve(?string $rel): ?string
    {
        if ($rel === null || !preg_match('#^\d+/(photo|signature)/[0-9a-f-]{36}\.(png|jpg|webp|gif)$#', $rel)) {
            return null;
        }
        return self::root() . '/' . $rel;
    }
}
