<?php
declare(strict_types=1);

/**
 * Configuración por variables de entorno (Render → Environment). Ningún secreto vive en el código.
 * En local se puede usar un archivo .env (está en .gitignore) con líneas CLAVE=valor.
 */
final class Config
{
    private static ?array $valores = null;

    public static function get(string $clave, string $porDefecto = ''): string
    {
        if (self::$valores === null) {
            self::$valores = [];
            $env = dirname(__DIR__) . '/.env';
            if (is_file($env)) {
                foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
                    if ($linea[0] === '#' || !str_contains($linea, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $linea, 2);
                    self::$valores[trim($k)] = trim($v);
                }
            }
        }
        $v = getenv($clave);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return self::$valores[$clave] ?? $porDefecto;
    }

    public static function desarrollo(): bool
    {
        return self::get('APP_ENV', 'produccion') === 'desarrollo';
    }
}
