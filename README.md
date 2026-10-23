# Magento 2 Khipu Plugin

khipu payment gateway Magento 2 plugin, version 2.8.0.

Requires PHP 8.1 or later and Magento 2.4.4 or later.

Tested on Magento 2.4.8 with PHP 8.3.

You can sign up for khipu account at <https://khipu.com>

## Install via Composer

You can install the khipu plugin via [Composer](http://getcomposer.org/). Run the following command in your terminal:

1. Go to your Magento 2 root folder.

2. Enter following commands to install plugin:

    ```bash
    composer require khipu/magento2-khipu
    ```

   Wait while dependencies are updated.

3. Enter following commands to enable plugin:

    ```bash
    php bin/magento module:enable Khipu_Payment --clear-static-content
    php bin/magento setup:upgrade
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy
    php bin/magento cache:flush 
    ```

   (`setup:static-content:deploy` is only needed in production mode.)

4. Enable and configure Khipu plugin in Magento Admin under `Stores / Configuration / Sales / Payment Methods / Khipu`.


## Install via FTP

> Check first that the store runs PHP 8.1 or later (`php -v`). Composer verifies it automatically; a manual copy does not, and on an older PHP the checkout stops working.

1. Copia y descomprime el archivo en la carpeta de plugins de Magento
2. Enter following commands to enable plugin:

    ```bash
    php bin/magento module:enable Khipu_Payment --clear-static-content
    php bin/magento setup:upgrade
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy
    php bin/magento cache:flush 
    ```

   (`setup:static-content:deploy` is only needed in production mode.)

4. Enable and configure Khipu plugin in Magento Admin under `Stores / Configuration / Sales / Payment Methods / Khipu`.

## One button per payment method (2.8.0)

> **Upgrading to 2.8.0:** after updating, go to `Stores / Configuration / Sales / Payment Methods / Khipu` and press **Conectar** to get one button per payment method. Until then the checkout keeps the single Khipu button of 2.7.x (including the embedded payment if it was enabled), so the store keeps charging.
>
> (Después de actualizar a 2.8.0, entra a Stores › Configuration › Sales › Payment Methods › Khipu y aprieta Conectar para tener un botón por medio de pago. Hasta entonces el checkout mantiene el botón único de Khipu de 2.7.x, así que la tienda sigue cobrando.)

- Enter your API key, merchant id and secret and press **Conectar**; it saves the credentials itself. The plugin reads the payment methods enabled in your Khipu account and fills a table in the same screen.
- The checkout shows one button per active payment method. Each row of the table has an active switch, the method's name (shown in the checkout as "Khipu - {name}", for example "Khipu - Transferencia simplificada") and a mode (`embedded` or `redirect`). New methods start active. Pressing **Conectar** again adds new methods without changing the rows you already configured. If you change the Merchant_ID, the checkout falls back to the single Khipu button until you press **Conectar** again. If you switch every method off, Khipu is hidden. Orders created without a method (for example through REST or GraphQL) are charged without restricting the method.
- Simplified transfer (`SIMPLIFIED_TRANSFER`) is embedded by default (Khipu modal inside the checkout, KWS) and you can switch it to redirect. The other methods redirect to Khipu's payment page. Direct debit (`DIRECT_DEBIT`) is not offered, even if the account has it. Simplified transfer is always listed first; the other methods keep the order Khipu returns.
- The old "Pago embebido" switch and the "title" field were removed from the admin; titles are now per method in the table. Their saved values are still used by the single button until you press **Conectar**.
- Webhook notifications are rejected (HTTP 401) unless their `X-Khipu-Signature` matches the store's secret, and calls to the Khipu API verify TLS certificates.
- An order never gets two Khipu charges: if it already has a pending one (double click, reload, retry), it is reused; if it is already paid, the customer goes to the success page. A repeated notification for an already registered payment is acknowledged without invoicing or emailing again.
- Requires the Magento_Csp module. The plugin ships `etc/csp_whitelist.xml` with js.khipu.com and the hosts KWS uses.
- When a customer abandons the payment, the plugin first deletes the charge in Khipu and only then cancels the order. If Khipu does not allow it (for example, the charge is already paid), the order stays pending and the customer sees a notice.
