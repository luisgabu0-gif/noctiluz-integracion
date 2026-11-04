# NOCTILUZ · Integración y back-office (Tarea 2)

Prototipo académico de la asignatura Soluciones Informáticas para la Empresa (UCAM). No hay actividad comercial real: los pagos son de sandbox y los clientes de prueba son ficticios.

Este repositorio conecta la tienda de la Tarea 1 con un back-office independiente mediante flujos de n8n, e incorpora un agente de IA supervisado por personas.

| Pieza | Dónde | Código |
|---|---|---|
| Tienda (front-office) | https://www.noctiluz.onl (DonDominio, PHP + MySQL) | repositorio `noctiluz-ucam` |
| n8n | https://noctiluz-n8n.onrender.com (Render) | `n8n/` |
| Back-office | https://noctiluz-backoffice.onrender.com (Render, PHP + PostgreSQL) | `backoffice/` |
| Mensajería | Bot de Telegram | `n8n/04_telegram.json` |
| IA | Gemini (`gemini-3.5-flash`) con *function calling* | `n8n/03_agente_incidencias.json` |

## Cómo funciona

```
Tienda ──webhook──▶ n8n ──API──▶ Back-office ──▶ cuadro de mando
  ▲                  │  ▲             │
  └──── estados ─────┘  └── estados ──┘
                     │
                  Gemini ──▶ propuesta ──▶ Telegram (una persona aprueba) ──▶ cliente
```

1. **Pedido.** La tienda guarda el evento en una cola y lo envía a n8n. n8n valida el mensaje, lo traduce y crea el pedido en el back-office.
2. **Cambio de estado.** Funciona en los dos sentidos. Si la otra parte no responde, el cambio queda pendiente: la tienda reintenta sola y el back-office ofrece un botón de reintento.
3. **Incidencia.** Se crea el ticket y el agente consulta el pedido y el historial del cliente. Después propone una acción, y una persona la aprueba o rechaza por Telegram antes de que se ejecute nada.

El formato de los mensajes está en `docs/contrato_integracion.md` del repositorio de la tienda, con los payloads que envía la tienda en `docs/payloads/`. La carpeta `payloads/` de este repositorio tiene el formato de entrada de la API del back-office.

## Instalación

### 1. Back-office en Render

Es un Web Service de tipo Docker con la carpeta `backoffice` como raíz. Crea sus tablas solo al arrancar. Las variables están en `backoffice/.env.example`.

Comprobación: `https://noctiluz-backoffice.onrender.com/api/salud.php`.

### 2. n8n

Variables de entorno, además de las de la base de datos (ejemplo en `n8n/.env.example`):

| Variable | Valor |
|---|---|
| `WEBHOOK_URL` | `https://noctiluz-n8n.onrender.com/` |
| `N8N_PROXY_HOPS` | `1` |
| `N8N_RUNNERS_GRANT_TOKEN_TTL` | `120` |
| `N8N_BLOCK_ENV_ACCESS_IN_NODE` | `false` |
| `TELEGRAM_CHAT_SOPORTE` | ID del chat del equipo, que recibe las propuestas |
| `NODE_OPTIONS` | `--max-old-space-size=384` |
| `N8N_DIAGNOSTICS_ENABLED` | `false` |
| `N8N_VERSION_NOTIFICATIONS_ENABLED` | `false` |
| `N8N_TEMPLATES_ENABLED` | `false` |
| `EXECUTIONS_DATA_PRUNE` | `true` |
| `EXECUTIONS_DATA_MAX_AGE` | `72` |

Credenciales que hay que crear, con estos nombres exactos, antes de importar los flujos:

| Nombre | Tipo | Cabecera y valor |
|---|---|---|
| `NOCTILUZ Tienda` | Header Auth | `Authorization` · `Bearer ` + `integracion.token` de la tienda |
| `NOCTILUZ Back-office` | Header Auth | `X-Api-Token` · `BACKOFFICE_API_TOKEN` |
| `NOCTILUZ Webhook back-office` | Header Auth | `X-Noctiluz-Token` · `N8N_WEBHOOK_TOKEN` |
| `Telegram account` | Telegram API | Token del bot |
| `Google Gemini(PaLM) Api account` | Google Gemini(PaLM) API | Clave de Gemini |

Importa primero `99_errores.json` y después los flujos 1 a 4. Tras importar cada uno, guárdalo y recarga la página: hasta entonces el desplegable de credenciales puede salir vacío o mostrar un identificador. Comprueba la credencial de cada nodo y publícalo. En los flujos 1 a 4, elige `NOCTILUZ · Errores` en Settings → Error Workflow.

> En Render gratuito (512 MB) los nodos de IA de LangChain agotan la memoria. Por eso el agente llama a Gemini con un nodo HTTP Request y el bucle de herramientas está hecho con nodos normales.

### 3. Tienda

1. Ejecuta `database/actualizar_integracion.sql` en phpMyAdmin.
2. Sube los archivos nuevos.
3. Añade a `app/config.php` el bloque `integracion` de `app/config.ejemplo.php`, con `activo` en `true`.

Tres valores tienen que coincidir entre sistemas:

| Valor | Dónde va |
|---|---|
| Token de la tienda | `integracion.token`, `TIENDA_API_TOKEN` del back-office y credencial `NOCTILUZ Tienda` de n8n (con `Bearer `) |
| Token del webhook de estado | `N8N_WEBHOOK_TOKEN` del back-office y credencial `NOCTILUZ Webhook back-office` |
| Secreto de Telegram | `integracion.secreto_telegram` y `TELEGRAM_VINCULO_SECRETO` del back-office |

## Ejecución y pruebas

Con los flujos publicados:

| Prueba | Cómo | Resultado esperado |
|---|---|---|
| Escenario 1 | Haz un pedido en la tienda | Aparece en Pedidos del back-office |
| Escenario 2 | Cambia el estado en la tienda o en el back-office | El otro sistema se actualiza |
| Escenario 3 | Abre una incidencia con un pedido | Ticket creado y propuesta en Telegram |
| Duplicado | Envía dos veces `docs/payloads/escenario1_order_created.json` (repo de la tienda) al webhook | La segunda vez no se crea nada |
| Datos incompletos | Envía `docs/payloads/error_pedido_sin_lineas.json` al webhook | Rechazo 422 y fila de error en Ejecuciones |

## Usuarios de prueba

- **Back-office:** el usuario de `ADMIN_EMAIL` y `ADMIN_PASSWORD`. No está en este repositorio; el equipo lo facilita en la entrega.
- **Tienda:** los del README de su repositorio. Usa siempre datos ficticios con emails `@ejemplo.test`.
- **PayPal:** cuenta Personal del sandbox.

## Limitaciones conocidas

- Render gratuito duerme los servicios tras 15 minutos sin tráfico y tardan cerca de un minuto en despertar. El primer evento tras una pausa falla y entra por la cola de reintentos.
- n8n tiene 512 MB en Render gratuito. Sin ajustes de memoria se reiniciaba con salida 134 y el editor mostraba `Cannot GET /` o `Connection lost`. Conviene usar una sola pestaña del editor.
- Los reintentos de la tienda necesitan que algo la active: una visita a la tienda o el flujo programado de n8n, que solo corre con n8n despierto.
- Si el segundo sistema no responde a un cambio hecho en el back-office, hay que pulsar «Reintentar» en el panel.
- Una propuesta aprobada desde el panel web guarda la decisión, pero solo la aprobación por Telegram envía la respuesta al cliente.
- El flujo `NOCTILUZ · Errores` no se ha podido forzar en las pruebas, porque los demás flujos tratan sus errores en sus propias ramas.
- Un `id` de evento repetido se considera duplicado sin comparar su contenido.
- El agente depende de la disponibilidad de Gemini. Si falla, la incidencia se queda creada y el equipo recibe un aviso para gestionarla a mano.
- Las métricas describen las pruebas del prototipo, no un negocio real.

## Secretos

Ningún token ni clave está en el repositorio. Los valores reales viven en las variables de Render, en las credenciales de n8n y en el `config.php` del hosting.
