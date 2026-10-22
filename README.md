# Magento 2 Khipu Plugin

khipu payment gateway Magento 2.7.0 plugin.

This version is compatible with Magento 2.3 to 2.6

Tested on Magento 2.4.8.

You can sign up for khipu account at <https://khipu.com>

## Install via Composer

You can install Magento 2.7.0 khipu plugin via [Composer](http://getcomposer.org/). Run the following command in your terminal:

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

> **Upgrading to 2.8.0:** after updating, go to `Stores / Configuration / Sales / Payment Methods / Khipu` and press **Conectar**. Until then Khipu does not appear in the checkout.
>
> (Después de actualizar a 2.8.0, entra a Stores › Configuration › Sales › Payment Methods › Khipu y aprieta Conectar. Hasta entonces Khipu no aparece en el checkout.)

- Enter your API key, merchant id and secret and press **Conectar**; it saves the credentials itself. The plugin reads the payment methods enabled in your Khipu account and fills a table in the same screen.
- The checkout shows one button per active payment method. Each row of the table has an active switch, a title and a mode (`embedded` or `redirect`). New methods start active. Pressing **Conectar** again adds new methods without changing the rows you already configured. If you change the Merchant_ID, Khipu is hidden from the checkout until you press **Conectar** again.
- Simplified transfer (`SIMPLIFIED_TRANSFER`) is embedded by default (Khipu modal inside the checkout, KWS) and you can switch it to redirect. The other methods redirect to Khipu's payment page. Direct debit (`DIRECT_DEBIT`) is not offered, even if the account has it. Simplified transfer is always listed first; the other methods keep the order Khipu returns.
- The old "Pago embebido" switch and the "title" field were removed; titles are now per method in the table.
- Requires the Magento_Csp module. The plugin ships `etc/csp_whitelist.xml` with js.khipu.com and the hosts KWS uses.
- When a customer abandons the payment, the plugin first deletes the charge in Khipu and only then cancels the order. If Khipu does not allow it (for example, the charge is already paid), the order stays pending and the customer sees a notice.
