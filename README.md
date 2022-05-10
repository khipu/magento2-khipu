# Magento 2 Khipu Plugin

khipu payment gateway Magento 2 plugin.

This version is compatible with Magento 2.0, 2.1 and 2.2

You can sign up for khipu account at <https://khipu.com>

## Install via Composer

You can install Magento 2 khipu plugin via [Composer](http://getcomposer.org/). Run the following command in your terminal:

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
    ```

4. Enable and configure Khipu plugin in Magento Admin under `Stores / Configuration / Payment Methods / Khipu`.
