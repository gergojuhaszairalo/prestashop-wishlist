<?php

declare(strict_types=1);

/**
 * 1.0.1: storefront redesign and guest-list claim on login.
 * No schema change. Registers displayTop for the mobile header entry
 * (displayNav2 is hidden below 768px in Hummingbird).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    return $module->registerHook('displayTop');
}
