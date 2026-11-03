<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/inicio.php';
require dirname(__DIR__) . '/app/Panel.php';

Panel::iniciarSesion();
if (Panel::usuario() !== null) {
    header('Location: panel.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Panel::comprobarCsrf();
    if (Panel::entrar((string) ($_POST['email'] ?? ''), (string) ($_POST['clave'] ?? ''))) {
        header('Location: panel.php');
        exit;
    }
    $error = 'Email o contraseña incorrectos.';
}
$sinUsuarios = (int) Db::valor('SELECT COUNT(*) FROM usuarios') === 0;

Panel::cabecera('Entrar');
?>
<section class="tarjeta acceso">
  <h1>Acceso del equipo</h1>
  <p class="suave">Panel interno de operaciones. La tienda y este back-office son sistemas distintos, con bases de datos distintas; se comunican a través de n8n.</p>
  <?php if ($sinUsuarios): ?>
    <p class="aviso aviso-error">Todavía no hay usuarios. Define <code>ADMIN_EMAIL</code> y <code>ADMIN_PASSWORD</code> (mín. 10 caracteres) en las variables de entorno y recarga.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="formulario">
    <?= Panel::csrf() ?>
    <label>Email <input type="email" name="email" required autocomplete="username"></label>
    <label>Contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
    <button type="submit">Entrar</button>
  </form>
</section>
<?php Panel::pie();
