<?php

if (class_exists(Symfony\Component\Dotenv\Dotenv::class) && file_exists(_PS_MODULE_DIR_ . 'ps_onepagecheckout/.env')) {
    try {
        (new Symfony\Component\Dotenv\Dotenv())->load(_PS_MODULE_DIR_ . 'ps_onepagecheckout/.env');
    } catch (Throwable $e) {
    }
}
