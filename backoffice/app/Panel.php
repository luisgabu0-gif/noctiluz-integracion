<?php
declare(strict_types=1);

/** Sesión, CSRF y plantilla HTML del panel del back-office. */
final class Panel
{
    public static function iniciarSesion(): void
    {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS']),
        ]);
        session_name('nczbo');
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function exigirUsuario(): array
    {
        $u = self::usuario();
        if ($u === null) {
            header('Location: index.php');
            exit;
        }
        return $u;
    }

    public static function entrar(string $email, string $clave): bool
    {
        $u = Db::uno('SELECT id, email, nombre, clave_hash, rol FROM usuarios WHERE email = ?', [mb_strtolower(trim($email))]);
        if ($u === null || !password_verify($clave, $u['clave_hash'])) {
            usleep(400000); // frena los intentos por fuerza bruta
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['id' => (int) $u['id'], 'email' => $u['email'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
        return true;
    }

    public static function comprobarCsrf(): void
    {
        $t = $_POST['csrf'] ?? '';
        if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(400);
            exit('Formulario caducado. Vuelve atrás y recarga la página.');
        }
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
    }

    public static function aviso(string $texto, string $tipo = 'ok'): void
    {
        $_SESSION['aviso'] = [$texto, $tipo];
    }

    public static function cabecera(string $titulo, string $activa = ''): void
    {
        $u = self::usuario();
        $menu = ['resumen' => 'Cuadro de mando', 'pedidos' => 'Pedidos', 'incidencias' => 'Incidencias', 'agente' => 'Agente IA', 'ejecuciones' => 'Ejecuciones'];
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex, nofollow"><title>' . e($titulo) . ' · Back-office NOCTILUZ</title>';
        echo '<link rel="stylesheet" href="assets/panel.css"></head><body>';
        echo '<header class="barra"><a class="marca" href="panel.php"><span class="punto"></span>NOCTILUZ <small>back-office</small></a>';
        if ($u !== null) {
            echo '<nav>';
            foreach ($menu as $clave => $texto) {
                echo '<a href="panel.php?vista=' . $clave . '"' . ($clave === $activa ? ' aria-current="page"' : '') . '>' . $texto . '</a>';
            }
            echo '</nav><span class="usuario">' . e($u['nombre']) . ' · <a href="salir.php">Salir</a></span>';
        }
        echo '</header><main>';
        if (!empty($_SESSION['aviso'])) {
            [$texto, $tipo] = $_SESSION['aviso'];
            unset($_SESSION['aviso']);
            echo '<p class="aviso aviso-' . e($tipo) . '" role="status">' . e($texto) . '</p>';
        }
    }

    public static function pie(): void
    {
        echo '</main><footer class="pie">Prototipo académico sin actividad comercial real · back-office independiente de la tienda · datos de prueba ficticios</footer></body></html>';
    }
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function eur($v): string
{
    return $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' €';
}

function fecha($v): string
{
    return $v ? date('d/m/Y H:i', strtotime((string) $v)) : '—';
}

function etiquetaEstado(string $estado): string
{
    return '<span class="estado estado-' . e($estado) . '">' . e(Reglas::ESTADOS[$estado] ?? $estado) . '</span>';
}
