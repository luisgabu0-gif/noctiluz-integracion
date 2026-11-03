<?php
declare(strict_types=1);

/** Capa de persistencia: PostgreSQL en Render, SQLite para probar en local. Mismo estilo que la tienda. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conexion(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $url = Config::get('DATABASE_URL');
        if ($url === '' || str_starts_with($url, 'sqlite:')) {
            $ruta = $url !== '' ? substr($url, 7) : dirname(__DIR__) . '/datos/backoffice.sqlite';
            if (!is_dir(dirname($ruta))) {
                mkdir(dirname($ruta), 0775, true);
            }
            self::$pdo = new PDO('sqlite:' . $ruta, null, null, $opciones);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            // Render da la URL como postgres://usuario:clave@host:puerto/base
            $p = parse_url($url);
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'] ?? '', '/'));
            // La URL interna de Render (host sin dominio) va por la red privada; la externa exige TLS
            if (str_contains($p['host'], '.')) {
                $dsn .= ';sslmode=require';
            }
            self::$pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), $opciones);
            // Tablas en su propio esquema para no mezclarse con las de n8n en la misma instancia
            self::$pdo->exec('CREATE SCHEMA IF NOT EXISTS backoffice');
            self::$pdo->exec('SET search_path TO backoffice');
        }
        return self::$pdo;
    }

    public static function esSqlite(): bool
    {
        return self::conexion()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    public static function uno(string $sql, array $params = []): ?array
    {
        $st = self::conexion()->prepare($sql);
        $st->execute($params);
        $fila = $st->fetch();
        return $fila === false ? null : $fila;
    }

    public static function todos(string $sql, array $params = []): array
    {
        $st = self::conexion()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function valor(string $sql, array $params = [])
    {
        $fila = self::uno($sql, $params);
        return $fila === null ? null : reset($fila);
    }

    /** Ejecuta una sentencia y devuelve el nº de filas afectadas. */
    public static function ejecutar(string $sql, array $params = []): int
    {
        $st = self::conexion()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** INSERT que devuelve el id nuevo (RETURNING en PostgreSQL, lastInsertId en SQLite). */
    public static function insertar(string $sql, array $params = []): int
    {
        if (self::esSqlite()) {
            self::ejecutar($sql, $params);
            return (int) self::conexion()->lastInsertId();
        }
        return (int) self::valor($sql . ' RETURNING id', $params);
    }

    /** Ejecuta $fn dentro de una transacción: o se guarda todo o no se guarda nada. */
    public static function transaccion(callable $fn)
    {
        $pdo = self::conexion();
        $pdo->beginTransaction();
        try {
            $resultado = $fn();
            $pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function ahora(): string
{
    return date('Y-m-d H:i:s');
}

/** Normaliza una fecha ISO 8601 (la que manda n8n/la tienda) al formato de la base de datos. */
function fechaBd(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return ahora();
    }
    $t = strtotime($iso);
    return $t === false ? ahora() : date('Y-m-d H:i:s', $t);
}
