### Objetivo

Crear una pasarela de pago personalizada que conecte WooCommerce con la API de Mercado Pago para procesar cobros recurrentes automáticos, incorporando un panel de administración claro e intuitivo para su configuración y priorizando la sincronización mediante Webhooks.

### Detalles técnicos y dependencias

* **Plugins requeridos:**
* **WooCommerce:** Administra la tienda, el catálogo, el checkout y provee la estructura para los paneles de configuración de pagos.
* **WooCommerce Subscriptions:** Gestiona la lógica de recurrencia, ciclos y órdenes de renovación.


* **Credenciales de Mercado Pago:**
* Se utilizan el **Access Token** y la **Public Key** (de producción y prueba), configurables desde el panel de administración del plugin.


* **Interfaz de administración:**
* Panel integrado en los ajustes de pago de WooCommerce para ingresar credenciales, activar el modo Sandbox, verificar el estado del Webhook y revisar registros (*logs*) de depuración.


* **Integración de API:**
* **API de Preapproval:** Endpoint REST (`/preapproval`) para crear y administrar contratos, autenticado con `Authorization: Bearer YOUR_ACCESS_TOKEN`.
* **Webhooks / IPN:** Endpoint receptor para confirmar los pagos de cada ciclo de forma reactiva.


* **Requisitos del entorno:**
* PHP 7.4+ / 8.x.
* Funciones HTTP nativas de WordPress (`wp_remote_post` / `wp_remote_get`).



### Pasos del plan de desarrollo

1. **Inicialización, panel de administración y dependencias**
Crear la estructura básica del plugin y verificar que las dependencias estén activas. Desarrollar un panel de ajustes claro dentro de WooCommerce para configurar credenciales, alternar entre entorno de pruebas y producción, y mostrar la URL del Webhook generada para copiar en Mercado Pago.
2. **Procesamiento del pago inicial (Creación del contrato)**
Capturar la solicitud en el checkout usando las credenciales guardadas en el panel. Sincronizar los parámetros de WooCommerce (monto, frecuencia, intervalos y periodos de prueba) con el payload de Mercado Pago, enviar la petición a `/preapproval`, guardar el ID del contrato y redirigir al cliente.
3. **Gestión reactiva de renovaciones programadas**
Dado que Mercado Pago asume el control del calendario de cobros y debita automáticamente, el plugin actúa de forma reactiva. El sistema se configura para no intentar cobros directos por *cron*, delegando la confirmación de la renovación exclusivamente al Webhook.
4. **Recepción de notificaciones (Webhooks robustos)**
Desarrollar el receptor de notificaciones que escucha las alertas de Mercado Pago. Al recibir un cobro exitoso, el plugin localiza la suscripción, genera la orden de renovación en WooCommerce y la marca como pagada.
5. **Sincronización de cancelaciones y suspensiones**
Conectar los cambios de estado de WooCommerce con Mercado Pago. Si una suscripción se cancela o pausa desde la tienda, enviar una petición PUT a la API enviando el Access Token para actualizar el contrato (`cancelled` o `paused`) y detener los cobros automáticos.