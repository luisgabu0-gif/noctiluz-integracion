-- Back-office NOCTILUZ · PostgreSQL (Render)
-- Base de datos PROPIA del back-office: no comparte tablas con la tienda (MySQL en DonDominio).
-- Se aplica sola al arrancar (app/Esquema.php); todo es idempotente (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS usuarios (
  id         SERIAL PRIMARY KEY,
  email      VARCHAR(120) NOT NULL UNIQUE,
  nombre     VARCHAR(100) NOT NULL,
  clave_hash VARCHAR(255) NOT NULL,
  rol        VARCHAR(20)  NOT NULL DEFAULT 'operador',   -- admin | operador
  creado_en  TIMESTAMP    NOT NULL
);

CREATE TABLE IF NOT EXISTS clientes (
  id               SERIAL PRIMARY KEY,
  email            VARCHAR(120) NOT NULL UNIQUE,
  nombre           VARCHAR(100) NOT NULL,
  telegram_chat_id VARCHAR(40)  NULL,                    -- se rellena cuando el cliente pulsa el enlace del bot
  creado_en        TIMESTAMP    NOT NULL
);

CREATE TABLE IF NOT EXISTS pedidos (
  id             SERIAL PRIMARY KEY,
  codigo         VARCHAR(30)   NOT NULL UNIQUE,         -- mismo código que en la tienda (NCZ-...)
  cliente_id     INT           NOT NULL REFERENCES clientes(id),
  estado         VARCHAR(20)   NOT NULL,
  total          NUMERIC(10,2) NOT NULL,
  unidades       INT           NOT NULL DEFAULT 0,
  metodo_envio   VARCHAR(30)   NULL,
  creado_tienda  TIMESTAMP     NOT NULL,                -- occurred_at del evento order.created
  recibido_en    TIMESTAMP     NOT NULL,                -- cuándo llegó aquí (mide la latencia de integración)
  actualizado_en TIMESTAMP     NOT NULL
);

CREATE TABLE IF NOT EXISTS pedido_lineas (
  id          SERIAL PRIMARY KEY,
  pedido_id   INT           NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
  sku         VARCHAR(40)   NULL,
  descripcion VARCHAR(160)  NOT NULL,
  cantidad    INT           NOT NULL,
  importe     NUMERIC(10,2) NOT NULL
);

CREATE TABLE IF NOT EXISTS historial_estados (
  id        SERIAL PRIMARY KEY,
  pedido_id INT          NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
  de        VARCHAR(20)  NULL,
  a         VARCHAR(20)  NOT NULL,
  origen    VARCHAR(20)  NOT NULL,                      -- tienda | backoffice | agente
  actor     VARCHAR(120) NULL,
  motivo    VARCHAR(200) NULL,
  creado_en TIMESTAMP    NOT NULL
);

CREATE TABLE IF NOT EXISTS incidencias (
  id                  SERIAL PRIMARY KEY,
  codigo              VARCHAR(30)  NOT NULL UNIQUE,     -- INC-... de la tienda
  pedido_id           INT          NULL REFERENCES pedidos(id),
  cliente_id          INT          NULL REFERENCES clientes(id),
  motivo              VARCHAR(30)  NOT NULL,
  mensaje             TEXT         NULL,
  prioridad           VARCHAR(10)  NOT NULL DEFAULT 'media',   -- baja | media | alta
  estado              VARCHAR(20)  NOT NULL DEFAULT 'abierta', -- abierta | en_curso | resuelta
  notificado_telegram BOOLEAN      NOT NULL DEFAULT FALSE,
  creado_en           TIMESTAMP    NOT NULL,
  resuelta_en         TIMESTAMP    NULL
);

-- Idempotencia: si n8n reintenta y el mismo evento llega dos veces, la segunda no hace nada.
CREATE TABLE IF NOT EXISTS eventos_recibidos (
  event_id    VARCHAR(40) PRIMARY KEY,
  tipo        VARCHAR(40) NOT NULL,
  recibido_en TIMESTAMP   NOT NULL
);

-- Registro de ejecuciones de la integración (lo escribe n8n en cada flujo, también cuando falla).
CREATE TABLE IF NOT EXISTS ejecuciones (
  id            SERIAL PRIMARY KEY,
  flujo         VARCHAR(60)  NOT NULL,
  escenario     VARCHAR(30)  NOT NULL,                  -- pedido | estado | incidencia | agente | otro
  event_id      VARCHAR(40)  NULL,
  resultado     VARCHAR(20)  NOT NULL,                  -- ok | error | duplicado | ignorado
  paso_fallido  VARCHAR(80)  NULL,
  tipo_error    VARCHAR(20)  NULL,                      -- validacion | autenticacion | negocio | transitorio | desconocido
  causa         VARCHAR(300) NULL,
  datos_entrada TEXT         NULL,                      -- JSON recortado, solo datos ficticios
  duracion_ms   INT          NULL,
  creado_en     TIMESTAMP    NOT NULL
);

CREATE TABLE IF NOT EXISTS propuestas_agente (
  id                  SERIAL PRIMARY KEY,
  incidencia_id       INT           NOT NULL REFERENCES incidencias(id),
  resumen             VARCHAR(400)  NOT NULL,
  clasificacion       VARCHAR(30)   NOT NULL,
  prioridad           VARCHAR(10)   NOT NULL,
  accion_propuesta    VARCHAR(40)   NOT NULL,
  respuesta_cliente   TEXT          NULL,
  justificacion       VARCHAR(600)  NOT NULL,
  confianza           NUMERIC(3,2)  NOT NULL,
  requiere_aprobacion BOOLEAN       NOT NULL DEFAULT TRUE,
  estado              VARCHAR(20)   NOT NULL DEFAULT 'pendiente',  -- pendiente | aprobada | rechazada
  decidido_por        VARCHAR(120)  NULL,
  decidido_en         TIMESTAMP     NULL,
  modelo              VARCHAR(60)   NULL,
  creado_en           TIMESTAMP     NOT NULL
);

-- Cambios de estado hechos en el back-office que hay que llevar a la tienda (vía n8n).
CREATE TABLE IF NOT EXISTS sincronizaciones (
  id          SERIAL PRIMARY KEY,
  pedido_id   INT          NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
  estado      VARCHAR(20)  NOT NULL,                    -- estado que se quiere llevar a la tienda
  resultado   VARCHAR(20)  NOT NULL DEFAULT 'pendiente', -- pendiente | ok | error
  intentos    INT          NOT NULL DEFAULT 0,
  ultimo_error VARCHAR(300) NULL,
  creado_en   TIMESTAMP    NOT NULL,
  actualizado_en TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ejec_fecha ON ejecuciones (creado_en);
CREATE INDEX IF NOT EXISTS idx_ped_recibido ON pedidos (recibido_en);
CREATE INDEX IF NOT EXISTS idx_inc_estado ON incidencias (estado);
