---
title: Vender cuando el internet se cae (POS sin conexión)
audience: owner
group: Taking orders
updated: 2026-10-12
---

# Vender cuando el internet se cae (POS sin conexión)

Tu caja sigue vendiendo cuando el internet o el servidor no están disponibles, durante un tiempo limitado, y envía todo a tu panel cuando vuelve la conexión. Esta página explica qué sigue funcionando, qué no y cuáles son los límites.

## Qué funciona sin conexión

- **Registrar ventas** desde el menú que tu caja descargó por última vez: productos, opciones, variantes, impuestos, cargo por servicio y los descuentos que tu personal tiene permitido aplicar.
- **Pagos en efectivo y pagos manuales** (por ejemplo, una transferencia bancaria que confirmas tú mismo), con el cambio calculado en la caja.
- **Recibos** impresos desde la caja, incluido el código QR donde tu país lo exige.
- **Anular** una venta hecha en esta caja, en este turno, antes de que se haya sincronizado (con un PIN de gerente si tu tienda lo pide).
- **Abrir y cerrar el cajón**, ingresos y retiros de efectivo, y el resumen del turno de esta caja.
- **Pagos con tarjeta** guardados en la caja para después: solo en una caja con la app del lector de tarjetas, solo dentro de los límites de tarjeta indicados abajo y solo una vez que tu proveedor de pagos haya confirmado esta función para tu tienda. Hasta entonces, los pagos con tarjeta sin conexión están desactivados y la caja pide otra forma de pago.

Cuando vuelve la conexión, la caja envía cada venta, pago y anulación a tu panel en orden, una sola vez cada uno. Una venta nunca se crea dos veces, ni siquiera si la caja se reinicia o la conexión se corta a mitad del envío.

## Límites de tiempo

| Límite | Predeterminado | Máximo |
|---|---|---|
| Cuánto tiempo puede vender una caja sin contactar al servidor | 12 horas | 24 horas |
| Cuánto tiempo se guarda en la caja una venta que no se puede enviar | 7 días | 7 días |

- Cuando se agota el tiempo sin conexión, la caja se niega a aceptar ventas **nuevas** y muestra "conecta para continuar". Aun así sigue enviando lo que tiene y sigue reimprimiendo recibos. Las horas se cuentan desde la última vez que la caja se conectó al servidor.
- Si una venta sigue en espera después de 7 días, la caja detiene las ventas nuevas y muestra una alerta de gerente. Nada se borra antes de haber sido enviado.
- Los dueños de tienda pueden elegir un tiempo sin conexión más corto en la configuración del POS; el máximo de la plataforma es 24 horas.

## Límites de tarjeta

Los pagos con tarjeta sin conexión se guardan en la caja mediante la app del lector de tarjetas hasta que el proveedor pueda procesarlos. La caja verifica estos límites **antes** de leer la tarjeta:

| Límite | Valor |
|---|---|
| Un pago con tarjeta | hasta SAR 250 |
| Todos los pagos con tarjeta aún no enviados, por caja | hasta SAR 2,500 |

Para tu tienda puedes establecer límites más bajos, no más altos. Si se supera un límite, la caja muestra "necesita conexión" y eliges otra forma de pago.

**La tienda asume el riesgo.** Un pago con tarjeta sin conexión no se puede controlar en el momento de la venta. Si luego la tarjeta se rechaza, la venta permanece en tus registros y aparece como **tarjeta rechazada después de una venta sin conexión** en esa orden, en el reporte del turno y en la lista de **excepciones sin conexión**, con el monto, la hora, el miembro del personal y la caja. Juwri no le vuelve a cobrar al cliente por ti. Si recuperas el dinero de otra forma (efectivo, transferencia), un gerente lo marca como "cobrado de otra forma", lo que agrega un pago de corrección y nunca borra el rechazado. La caja y Juwri nunca almacenan los números de las tarjetas.

## Las cajas de navegador son solo de efectivo

Una caja que funciona en un navegador web (no en la app del lector de tarjetas) puede vender sin conexión con **efectivo y pagos manuales únicamente**. No tiene app de lector de tarjetas, por lo que los pagos con tarjeta sin conexión no están disponibles allí.

Un navegador también puede borrar sus propios datos guardados cuando al dispositivo le falta espacio o el navegador se restablece. Para proteger tus ventas, la caja le pide al navegador que conserve sus datos y muestra una advertencia al respecto. Si una caja de navegador puede estar sin conexión durante mucho tiempo, envía sus ventas apenas vuelva la conexión y prefiere la app de tablet para los puntos de venta concurridos.

## Qué verá tu personal

- Un aviso "Sin conexión" con el número de ventas en espera y cuánto tiempo sin conexión queda.
- Precios, impuestos, redondeo y recibos funcionan igual que en línea. Si se cambió un precio en el panel mientras la caja estaba sin conexión, la venta conserva el precio que el cliente vio y pagó.
- Después de sincronizar, una breve lista de **excepciones sin conexión** para el turno muestra cualquier cosa que un gerente deba revisar.

## Lo que necesita conexión (excepciones)

Esto no funciona sin conexión. La caja lo indica cuando lo intentas:

- Pagos con tarjeta en línea y enlaces de pago, pagos con monedero o crédito almacenado, tarjetas de regalo (venderlas o canjearlas), canje de puntos de fidelidad (los puntos se siguen ganando cuando la venta se sincroniza), cupones que necesitan el servidor (códigos de un solo uso o específicos del cliente).
- Reembolsos de ventas no hechas en esta caja en este turno, cualquier reembolso con tarjeta y anular un pago con tarjeta después de que se haya enviado.
- Consultar clientes (escribir un nombre y un teléfono en una venta está bien) y crear cuentas de clientes.
- Pedidos en línea entrantes y de apps de reparto, asignación de repartidores, la pantalla de cocina en otros dispositivos, mover o combinar una mesa con otra caja, reservas y la lista de espera.
- Cambiar precios, productos, impuestos, personal o configuración, abrir una caja que nunca se ha conectado e iniciar la sesión de un miembro del personal que no estaba en la última lista descargada.
- Reportes más allá del resumen del turno de esta caja (el reporte de fin de día solo es definitivo después de que las ventas se sincronizan).
- Facturas fiscales que necesitan autorización del gobierno (por ejemplo, facturas de empresa a empresa en Arabia Saudita). Los recibos simplificados para clientes se firman en la propia caja.
- Vender después de que se haya agotado el tiempo sin conexión o el límite de 7 días.

## Cuando dos cosas difieren

- **Stock:** la caja puede vender un artículo por debajo de stock cero mientras está sin conexión. La venta se acepta cuando se sincroniza y el artículo aparece en un reporte de **sobreventa** para ti. Los artículos marcados como "nunca sobrevender" se bloquean en cero en la caja.
- **La misma mesa en dos cajas:** ambas ventas se conservan como órdenes separadas y se marcan.
- **Un turno cerrado desde otro dispositivo mientras esta caja estaba sin conexión:** las ventas tardías se agregan al turno correcto y su reporte se vuelve a emitir con una sección de "ventas tardías".

## Si se pierde o roban una caja

Pídele a quien administra tu cuenta de Juwri que **revoque** la caja. La próxima vez que se conecte a internet, borra todo lo que guarda, después de enviar las ventas que pueda. Si nunca se conecta a internet, borra sus datos por sí sola en el siguiente inicio una vez que ha estado revocada durante 3 días. Todo lo almacenado en la caja está cifrado y la clave nunca sale del dispositivo.

## Páginas relacionadas

- [Punto de venta (POS)](../pos.md) — la caja registradora, los pagos y el cajón.
- [Cuentas del personal](../staff.md) — roles, PINs y permisos por persona.
- [Gestión de pedidos](../orders.md) — dónde aparecen las ventas sin conexión sincronizadas.