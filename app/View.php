<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal view renderer with layout support. All output escaped via e() in templates.
 */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'base'): string
    {
        $file = APP_ROOT . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        $content = (string)ob_get_clean();

        if ($layout === null) {
            return $content;
        }
        $layoutFile = APP_ROOT . '/views/layouts/' . $layout . '.php';
        ob_start();
        include $layoutFile;
        return (string)ob_get_clean();
    }

    public static function show(string $template, array $data = [], ?string $layout = 'base'): never
    {
        echo self::render($template, $data, $layout);
        exit;
    }
}
