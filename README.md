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

## Embedded payment (2.7.0)

- `Stores / Configuration / Sales / Payment Methods / Khipu` has a "Pago embebido" (embedded payment) switch, off by default.
- When it is on, the payment opens in a Khipu modal (KWS) inside the checkout, but only if the Khipu account has simplified transfer enabled. Otherwise the customer is redirected to Khipu's payment page as usual.
- The modal only offers simplified transfer, even if the Khipu account has other payment methods enabled (manual transfer, direct debit, LigoPay, etc.).
- The plugin caches the account's payment methods for 30 seconds, so changes made in Khipu reach the checkout within 30 seconds. Use "Flush Magento Cache" (System / Cache Management) to apply them right away, and also after turning the switch on or off.
- Requires the Magento_Csp module. The plugin ships `etc/csp_whitelist.xml` with js.khipu.com and the hosts KWS uses.
- Behavior change that applies even with the switch off: when a customer abandons the payment, the plugin first deletes the charge in Khipu and only then cancels the order. If Khipu does not allow it (for example, the charge is already paid), the order stays pending and the customer sees a notice.
