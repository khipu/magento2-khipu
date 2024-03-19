# Magento 2 Khipu Plugin

khipu payment gateway Magento 2.4.10 plugin.

This version is compatible with Magento 2.3 to 2.6

You can sign up for khipu account at <https://khipu.com>

## Install via Composer

You can install Magento 2.4.10 khipu plugin via [Composer](http://getcomposer.org/). Run the following command in your terminal:

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
    php bin/magento cache:flush 
    ```

4. Enable and configure Khipu plugin in Magento Admin under `Stores / Configuration / Sales / Payment Methods / Khipu`.
