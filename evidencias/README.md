# Evidencias de la Tarea 2

Guarda aquí las capturas con estos nombres. Usa solo datos ficticios (`@ejemplo.test`) y comprueba que no se ve ningún token, contraseña ni URL de base de datos.

| Archivo | Qué debe verse | Apartado del enunciado |
|---|---|---|
| `01_backoffice_antes.png` | Pedidos del back-office antes de la prueba | 9.h |
| `02_pedido_tienda.png` | Pedido hecho en la tienda, con su código | Escenario 1 |
| `03_n8n_flujo1_ok.png` | Ejecución correcta del flujo 1 | 9.g |
| `04_backoffice_despues.png` | El mismo pedido con cliente, líneas, importe, estado y fecha | 9.h, escenario 1 |
| `05_estado_backoffice.png` | Cambio de estado en el back-office | Escenario 2 |
| `06_n8n_flujo2_ok.png` | Ejecución correcta del flujo 2 | 9.g |
| `07_estado_tienda.png` | Estado nuevo en el seguimiento de la tienda | Escenario 2 |
| `08_incidencia_backoffice.png` | Incidencia ligada al pedido y al cliente | Escenario 3 |
| `09_n8n_agente.png` | Ejecución del flujo 3 con las herramientas usadas | 13.f, 13.g |
| `10_propuesta_agente.png` | Salida estructurada de la propuesta | 13.h |
| `11_telegram_equipo.png` | Propuesta con los botones Aprobar y Rechazar | 13.i |
| `12_telegram_cliente.png` | Respuesta recibida por el cliente | 13.m |
| `13_decision_registrada.png` | Quién decidió y cuándo | 13.i |
| `14_error_422.png` | Evento rechazado y fila de error en Ejecuciones | 13.k |
| `15_duplicado.png` | Evento repetido sin crear un segundo pedido | Rúbrica: idempotencia |
| `16_ejecuciones.png` | Tabla Ejecuciones con correctas y fallidas | 13.e |
| `17_cuadro_mando.png` | Métricas y gráfico de pedidos | 9.i |

Evidencias complementarias:

| Archivo | Qué muestra |
|---|---|
| `03b_n8n_flujo1_detalle.png` | Detalle de la ruta del flujo 1 |
| `04b_detalle_pedido.png` | Detalle del pedido en el back-office |
| `07b_incidencia_tienda.png` | Confirmación de la incidencia en la tienda |
| `08b_incidencias_avisadas.png` | Incidencias con cliente avisado por Telegram |
| `11a_telegram_vinculado.png` | Vinculación del pedido con Telegram |
| `11b_propuesta3_telegram.png`, `11c_propuesta4_telegram.png` | Propuestas posteriores del agente |
| `13b_decision_aprobada_telegram.png`, `13c_decision_aprobada_backoffice.png` | Aprobación registrada |
| `14_15_prueba_errores.txt` | Resultado del 422 y del duplicado |
| `14b_fallo_gemini_telegram.webp` | Aviso de revisión manual cuando Gemini devuelve 503 |

`16_ejecuciones.png` muestra además la fila de error del 422, así que hace de `14_error_422.png`.
