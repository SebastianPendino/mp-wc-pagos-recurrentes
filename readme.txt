=== Mercado Pago - Pagos Recurrentes para WooCommerce Subscriptions ===
Contributors: mp-wc-pagos-recurrentes
Tags: mercado pago, woocommerce, subscriptions, suscripciones, pagos recurrentes, preapproval
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 6.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pasarela de pago que conecta WooCommerce Subscriptions con la API de Preapproval de Mercado Pago. Mercado Pago controla el calendario de cobros y el plugin reacciona a los Webhooks.

== Description ==

Este plugin añade a WooCommerce una pasarela de pago para cobrar suscripciones de forma automática con Mercado Pago, usando la API de Preapproval (contratos de suscripción).

A diferencia de otras pasarelas, **WooCommerce no programa los cobros**: es Mercado Pago quien debita al cliente en cada ciclo, y la tienda se sincroniza mediante Webhooks.

= Funcionamiento =

1. En el checkout, la pasarela crea el contrato (`/preapproval`) y redirige al cliente a Mercado Pago. El pedido queda en espera hasta que el cliente autorice el cobro.
2. Mercado Pago hace los cobros según su propio calendario.
3. Con cada notificación, el plugin consulta la API y actúa:
   * Cobro aprobado: crea la orden de renovación y la marca como pagada.
   * Cobro rechazado: deja la orden de renovación como fallida.
   * Contrato pausado: la suscripción pasa a "en espera".
   * Contrato cancelado: la suscripción se cancela.
4. Si cambias el estado de una suscripción en la tienda (cancelar, suspender o reactivar), el plugin lo envía a Mercado Pago. Los cambios que vienen de una notificación no se devuelven a la API, por lo que no se generan bucles.
5. Cada recurso tiene un bloqueo para que dos notificaciones simultáneas no creen órdenes duplicadas.

= Características =

* Compatible con WooCommerce Subscriptions.
* Compatible con HPOS (tablas personalizadas de pedidos).
* Compatible con el checkout basado en bloques.
* Modo Sandbox con credenciales de prueba independientes.
* Soporte de periodo de prueba (`free_trial`) y de duración limitada (`repetitions`).
* Validación opcional de la firma del Webhook (cabecera `x-signature`, HMAC SHA-256).
* Panel con la URL del Webhook, copia en un clic y estado de la última notificación recibida.
* Botón para verificar las credenciales.
* Registro de depuración propio en una carpeta protegida de uploads. Los tokens y claves nunca se escriben en el registro.
* La pasarela solo se ofrece cuando el carrito contiene una suscripción.

= Limitaciones impuestas por Mercado Pago =

* Solo se admite **una suscripción por pedido**.
* El primer cobro debe ser igual al importe recurrente: no se admiten cuotas de alta, cupones ni otros productos en el mismo pedido. Con periodo de prueba, el pedido inicial debe valer 0.
* Monedas admitidas: ARS, BRL, CLP, MXN, COP, PEN y UYU (filtrable con `mpwcr_supported_currencies`).
* Las frecuencias semanales se convierten a días y las anuales a meses.
* Mercado Pago solo envía notificaciones a URLs HTTPS accesibles públicamente.

= Alcance =

Este plugin gestiona **únicamente pagos recurrentes**. No ofrece pagos únicos (tarjeta, Pix, efectivo, etc.) para productos que no sean suscripciones.

== Installation ==

= Instalación del plugin =

1. Sube la carpeta del plugin a `wp-content/plugins/` y actívala. Requiere WooCommerce y WooCommerce Subscriptions activos.
2. Ve a WooCommerce > Ajustes > Pagos > Mercado Pago - Suscripciones.
3. Activa la pasarela e introduce el Access Token de prueba o de producción. Usa el botón "Verificar credenciales" tras guardar.
4. Copia la URL del Webhook que aparece en los ajustes y regístrala en el panel de Mercado Pago (Tus integraciones > Webhooks). Activa el evento "Planes y suscripciones".
5. Opcional: copia la clave secreta del Webhook de Mercado Pago en los ajustes para validar la firma de cada notificación.

= Qué obtener del panel de desarrolladores de Mercado Pago =

Los nombres de los menús cambian según el país y la fecha. Contrástalos con la documentación oficial (por ejemplo, mercadopago.com.ar/developers).

**1. Crear la aplicación**

1. Entra en "Tus integraciones" (`/developers/panel/app`) y pulsa "Crear aplicación".
2. Elige un producto de pagos online. Lo habitual es CheckOut Pro o el que ofrezca Suscripciones.
3. Con esa aplicación podrás ver las credenciales y configurar el Webhook.

**2. Credenciales**

* Access Token de producción (credenciales de producción): campo "Access Token (producción)".
* Public Key de producción: campo "Public Key (producción)". El plugin todavía no la usa, pero el campo existe.
* Access Token de prueba (credenciales de prueba): campo "Access Token (prueba)".
* Public Key de prueba: campo "Public Key (prueba)".
* Clave secreta del Webhook (Webhooks > Configurar notificaciones): campo "Clave secreta del Webhook".

Para activar las credenciales de producción, Mercado Pago pide completar los datos del negocio. Nunca compartas el Access Token.

**3. Cuentas de prueba**

Son obligatorias para probar suscripciones:

1. Ve a "Cuentas de prueba" y crea dos usuarios del mismo país: un vendedor y un comprador.
2. Usa las credenciales de prueba de la aplicación asociada al vendedor.
3. En el checkout de WooCommerce, usa el email del comprador de prueba como email de facturación. El plugin lo envía como `payer_email`.
4. En Mercado Pago, inicia sesión con el usuario comprador de prueba para autorizar el contrato.

**4. Tarjetas de prueba**

Están en la documentación de Mercado Pago, en Pruebas > Tarjetas de prueba. Para Argentina suelen ser:

* Mastercard: 5031 7557 3453 0604, CVV 123, vencimiento 11/30.
* Visa: 4509 9535 6623 3704, CVV 123, vencimiento 11/30.
* American Express: 3711 803032 57522, CVV 1234, vencimiento 11/30.

En Argentina el nombre del titular define el resultado, y el documento de prueba es `12345678`:

* `APRO`: pago aprobado.
* `OTHE`: rechazo por error general.
* `FUND`: fondos insuficientes.
* `SECU`: código de seguridad inválido.
* `CONT`: pago pendiente.

Los números y los nombres cambian según el país. Confírmalos en la documentación de tu país.

**5. Webhook**

1. Entra en tu aplicación y abre Webhooks > Configurar notificaciones.
2. Pega la URL que muestra el plugin en sus ajustes, con la forma `https://tutienda.com/wp-json/mpwcr/v1/webhook`. Debe ser HTTPS y pública.
3. Configúrala en modo de prueba y en modo productivo, que tienen URLs separadas.
4. Activa el evento "Planes y suscripciones". Cubre `subscription_preapproval` y `subscription_authorized_payment`, que son los dos que procesa el plugin.
5. Copia la clave secreta que aparece ahí.
6. El botón "Simular notificación" envía un ID falso. El plugin lo recibe, no lo encuentra en la API y responde 200, así que no se reintenta.

**6. API**

No hay que obtener ninguna clave de API aparte. Todo se hace con el Access Token en la cabecera `Authorization: Bearer ...` contra `https://api.mercadopago.com`. Endpoints que usa el plugin:

* `POST /preapproval`: crear el contrato.
* `GET /preapproval/{id}`: consultar el contrato.
* `PUT /preapproval/{id}`: cancelar, pausar o reactivar.
* `GET /authorized_payments/{id}`: consultar el cobro de un ciclo.
* `GET /users/me`: lo usa el botón "Verificar credenciales".

= Orden recomendado de puesta en marcha =

1. Crea la aplicación en el panel de Mercado Pago.
2. Crea los usuarios de prueba (vendedor y comprador).
3. Copia el Access Token de prueba al plugin con el Modo Sandbox activo.
4. Pulsa "Verificar credenciales".
5. Configura el Webhook de prueba y copia la clave secreta.
6. Haz una compra con el comprador de prueba y la tarjeta `APRO`.
7. Revisa el registro del plugin con la depuración activada.
8. Cuando todo funcione, cambia a las credenciales de producción, desactiva el Sandbox y configura el Webhook productivo.

== Frequently Asked Questions ==

= ¿Necesito el plugin oficial de Mercado Pago? =

No. Este plugin es independiente: usa su propio cliente de la API y sus propias credenciales. Sin embargo, no ofrece pagos únicos, por lo que si tu tienda vende productos que no son suscripciones necesitarás otra pasarela para ellos (por ejemplo, el plugin oficial).

= ¿Puedo desactivar el plugin oficial de Mercado Pago y usar solo este? =

Técnicamente sí, porque este plugin es independiente. Antes de hacerlo ten en cuenta que:

1. Solo gestiona suscripciones. La pasarela únicamente aparece cuando el carrito contiene una suscripción. Si vendes productos normales, al desactivar el oficial te quedas sin Mercado Pago para pagos únicos.
2. No migra suscripciones existentes. Las suscripciones que se cobran con otro plugin dejarían de sincronizarse con la tienda.
3. Hay que configurar de nuevo las credenciales y registrar la URL del Webhook de este plugin en Mercado Pago.
4. Haz pruebas en Sandbox (alta, cobro, rechazo y cancelación) antes de usarlo con clientes reales.

Recomendación: deja activos los dos. No chocan, porque este solo se muestra con suscripciones. Si solo vendes suscripciones y no tienes ninguna activa con el oficial, puedes desactivarlo sin problema.

= ¿Puedo usar este plugin junto al oficial? =

Sí. Cada uno registra su propia pasarela y su propia URL de Webhook. Este plugin solo aparece en el checkout cuando el carrito contiene una suscripción.

= ¿Qué pasa con las suscripciones creadas con otro plugin? =

Este plugin solo gestiona las suscripciones cuyo contrato creó él mismo (las que guardan el ID de contrato en `_mpwcr_preapproval_id`). Las suscripciones existentes de otras pasarelas no se migran.

= ¿Por qué no puedo usar cupones o cuotas de alta? =

Mercado Pago cobra siempre el importe recurrente en el primer ciclo. Si el total del pedido difiere, el plugin lo rechaza para evitar cobros incorrectos.

= ¿Dónde veo los registros? =

En los ajustes de la pasarela, sección "Registros". Los errores se registran siempre; el resto de eventos requiere activar el registro de depuración.

= ¿Cómo pruebo en modo Sandbox? =

Activa el modo Sandbox, usa las credenciales de prueba y compra con el email de un usuario comprador de prueba de Mercado Pago como email de facturación. Paga con una tarjeta de prueba (por ejemplo, titular `APRO` para un pago aprobado). Los detalles están en la sección Installation.

= ¿Necesito alguna clave de API además del Access Token? =

No. El plugin usa solo el Access Token en la cabecera `Authorization: Bearer` contra `https://api.mercadopago.com`.

= ¿Qué hace el botón "Simular notificación" de Mercado Pago? =

Envía un ID falso. El plugin lo recibe, no lo encuentra en la API y responde 200 para que Mercado Pago no lo reintente. Sirve para comprobar que la URL es accesible; la última notificación aparece en los ajustes de la pasarela.

== Changelog ==

= 1.0.0 =
* Versión inicial.
