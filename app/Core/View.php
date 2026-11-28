<?php
declare(strict_types=1);

namespace App\Core;

/** Plain PHP templates in app/Views. Always escape with e(). */
final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], string $layout = 'layout'): string
    {
        $content = self::partial($view, $data);
        if ($layout === '') {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content, 'title' => $data['title'] ?? 'Care Core']);
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $view, array $data = []): string
    {
        $file = ROOT_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $view");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    /** "12 Mar 2026". Takes a plain date, or a saved UTC time, which is shown as the UK date it fell on. */
    public static function date(?string $d, string $empty = '—'): string
    {
        return $d ? date('j M Y', self::stamp($d)) : $empty;
    }

    /** A saved UTC time in UK time: "12 Mar 2026 14:05". (Interview times are typed in and stored as UK time: do not use this for them.) */
    public static function dateTime(?string $utc, string $empty = '—'): string
    {
        return $utc ? date('j M Y H:i', self::stamp($utc)) : $empty;
    }

    /** Saved times ("2026-06-14 23:30:00", with no zone) are UTC. Anything else is read as it says. */
    private static function stamp(string $d): int
    {
        return (int) strtotime(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2}(\.\d+)?)?$/', $d) ? $d . ' UTC' : $d);
    }
}
