<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Return BankSync admin tabs.
 *
 * @return array<int,array<int,string>>
 */
function banksyncAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load('banksync@banksync');
    $head = array();
    $h = 0;

    $head[$h][0] = dol_buildpath('/banksync/admin/setup.php', 1);
    $head[$h][1] = $langs->trans('Settings');
    $head[$h][2] = 'settings';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'banksync@banksync');

    return $head;
}
