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

    public static function date(?string $d, string $empty = '—'): string
    {
        return $d ? date('j M Y', strtotime($d)) : $empty;
    }
}
