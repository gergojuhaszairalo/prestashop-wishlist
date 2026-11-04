<?php

declare(strict_types=1);

/**
 * 1.1.0: Stage 2, multiple named wishlists. No schema change: Stage 1 already stores lists
 * and entries separately, one default per owner. This script only:
 * - registers actionFrontControllerInitAfter (the guest-list claim now runs there, like the
 *   core cart's per-request reconciliation; a hook added only in install() would be missing here);
 * - repairs, idempotently, any owner left without a default (the oldest list becomes default)
 *   and normalises stray default_marker values. Healthy data is untouched; safe to run twice.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    $db = Db::getInstance();
    $lists = _DB_PREFIX_ . 'summitwishlist';

    $ok = $module->registerHook('actionFrontControllerInitAfter');

    // Only 1 or NULL are meaningful; anything else would bypass the one-default unique key.
    $ok = $ok && $db->execute('UPDATE `' . $lists . '` SET `default_marker` = NULL WHERE `default_marker` IS NOT NULL AND `default_marker` <> 1');

    // Owners with lists but no default: mark their oldest list (lowest id) as default.
    $ok = $ok && $db->execute(
        'UPDATE `' . $lists . '` w
        INNER JOIN (
            SELECT `id_shop`, `owner_key`, MIN(`id_wishlist`) AS `oldest`
            FROM `' . $lists . '`
            GROUP BY `id_shop`, `owner_key`
            HAVING SUM(`default_marker` = 1) = 0
        ) o ON o.`oldest` = w.`id_wishlist`
        SET w.`default_marker` = 1'
    );

    return (bool) $ok;
}
