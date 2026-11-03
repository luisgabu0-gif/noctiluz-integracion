<?php
declare(strict_types=1);

/** Error esperado: se devuelve al que llama con su código HTTP y el tipo, para que n8n sepa si reintentar. */
final class ErrorNegocio extends RuntimeException
{
    public function __construct(string $mensaje, public int $http = 422, public string $tipo = 'negocio')
    {
        parent::__construct($mensaje);
    }
}

/** Reglas del dominio. La máquina de estados es la misma que la de la tienda (ReglasNegocio.php). */
final class Reglas
{
    public const ESTADOS = [
        'creado'         => 'Creado (pendiente de pago)',
        'pagado'         => 'Pagado',
        'en_preparacion' => 'En preparación',
        'enviado'        => 'Enviado',
        'entregado'      => 'Entregado',
        'incidencia'     => 'Con incidencia',
        'cancelado'      => 'Cancelado',
    ];

    public const TRANSICIONES = [
        'creado'         => ['pagado', 'cancelado'],
        'pagado'         => ['en_preparacion', 'enviado', 'entregado', 'incidencia', 'cancelado'],
        'en_preparacion' => ['enviado', 'entregado', 'incidencia', 'cancelado'],
        'enviado'        => ['entregado', 'incidencia'],
        'entregado'      => ['incidencia'],
        'incidencia'     => ['en_preparacion', 'enviado', 'entregado', 'cancelado'],
        'cancelado'      => [],
    ];

    /** «pagado» solo lo pone la pasarela de la tienda; desde aquí nunca. */
    public const SOLO_TIENDA = ['pagado'];

    public const MOTIVOS = [
        'pedido'     => 'Duda sobre un pedido',
        'envio'      => 'Problema con el envío',
        'producto'   => 'Producto defectuoso',
        'devolucion' => 'Devolución o cambio',
        'otro'       => 'Otra consulta',
    ];

    /** Prioridad inicial por regla fija (sin IA): lo que deja al cliente sin producto va primero. */
    public const PRIORIDAD_POR_MOTIVO = [
        'producto' => 'alta', 'envio' => 'alta', 'devolucion' => 'media', 'pedido' => 'media', 'otro' => 'baja',
    ];

    /** Acciones que el agente puede PROPONER. Ninguna se ejecuta sin aprobación humana. */
    public const ACCIONES_AGENTE = [
        'responder_cliente'        => 'Responder al cliente',
        'marcar_pedido_incidencia' => 'Marcar el pedido con incidencia y responder',
        'escalar'                  => 'Escalar a una persona (prioridad alta)',
        'cerrar_incidencia'        => 'Cerrar la incidencia',
    ];

    public static function transicionesDesdePanel(string $estado): array
    {
        return array_values(array_diff(self::TRANSICIONES[$estado] ?? [], self::SOLO_TIENDA));
    }

    public static function puede(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }
}
