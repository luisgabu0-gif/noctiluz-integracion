<?php
declare(strict_types=1);

/**
 * Crea las tablas si no existen y el primer usuario del panel. Se ejecuta en cada arranque,
 * pero solo trabaja la primera vez: así no hace falta entrar a la base de datos de Render a mano.
 */
final class Esquema
{
    public static function asegurar(): void
    {
        $existe = Db::esSqlite()
            ? Db::valor("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sincronizaciones'")
            : Db::valor("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'backoffice' AND table_name = 'sincronizaciones'");
        if ((int) $existe === 0) {
            $sql = file_get_contents(dirname(__DIR__) . '/database/esquema_pgsql.sql');
            if (Db::esSqlite()) {
                $sql = str_replace('SERIAL PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            }
            $sql = preg_replace('/--[^\n]*/', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
                Db::conexion()->exec($sentencia);
            }
        }
        self::columnasNuevas();
        self::primerUsuario();
    }

    /** Columnas añadidas después del primer despliegue: la base de datos de Render ya existe y no se recrea. */
    private static function columnasNuevas(): void
    {
        foreach (['riesgos' => 'VARCHAR(400) NULL', 'datos_consultados' => 'TEXT NULL'] as $columna => $tipo) {
            $existe = Db::esSqlite()
                ? Db::valor("SELECT COUNT(*) FROM pragma_table_info('propuestas_agente') WHERE name = ?", [$columna])
                : Db::valor("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'backoffice' AND table_name = 'propuestas_agente' AND column_name = ?", [$columna]);
            if ((int) $existe === 0) {
                Db::conexion()->exec("ALTER TABLE propuestas_agente ADD COLUMN {$columna} {$tipo}");
            }
        }
    }

    private static function primerUsuario(): void
    {
        if ((int) Db::valor('SELECT COUNT(*) FROM usuarios') > 0) {
            return;
        }
        $email = mb_strtolower(Config::get('ADMIN_EMAIL'));
        $clave = Config::get('ADMIN_PASSWORD');
        if ($email === '' || strlen($clave) < 10) {
            return; // el panel avisará de que falta configurar ADMIN_EMAIL / ADMIN_PASSWORD
        }
        Db::insertar(
            'INSERT INTO usuarios (email, nombre, clave_hash, rol, creado_en) VALUES (?, ?, ?, ?, ?)',
            [$email, 'Administración', password_hash($clave, PASSWORD_DEFAULT), 'admin', ahora()]
        );
    }
}
