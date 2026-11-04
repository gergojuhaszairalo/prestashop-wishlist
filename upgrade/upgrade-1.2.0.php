<?php

declare(strict_types=1);

/**
 * 1.2.0: Stage 3, read-only back-office overview, and the example-modules structure (Composer
 * autoload, services in config/*.yml, DBAL repository). No schema or data change.
 *
 * Core only registers $tabs on install/reset, so an upgraded shop would have no
 * Customers > Wishlists entry without this script. Idempotent: an existing tab is updated in
 * place (democontrollertabs' manuallyInstallTab pattern), never duplicated.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    $definition = Summitwishlist::adminTab($module);

    $tabId = (int) Tab::getIdFromClassName($definition['class_name']);
    $tab = new Tab($tabId ?: null);
    $tab->active = true;
    $tab->class_name = $definition['class_name'];
    $tab->route_name = $definition['route_name'];
    $tab->module = $module->name;
    $tab->id_parent = (int) Tab::getIdFromClassName($definition['parent_class_name']);
    $tab->wording = $definition['wording'];
    $tab->wording_domain = $definition['wording_domain'];
    $tab->name = [];
    foreach (Language::getLanguages(false) as $language) {
        $tab->name[(int) $language['id_lang']] = $definition['name'][$language['locale']] ?? $definition['wording'];
    }

    return (bool) $tab->save();
}
