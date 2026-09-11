<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for BankSync.
 */
class modBankSync extends DolibarrModules
{
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;
        $this->numero = 500301;
        $this->rights_class = 'banksync';
        $this->family = 'financial';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'BankSyncDescription';
        $this->descriptionlong = 'BankSyncDescriptionLong';
        $this->version = '0.1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'bank';

        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array(),
            'moduleforexternal' => 0,
        );

        $this->dirs = array('/banksync/temp');
        $this->config_page_url = array('setup.php@banksync');
        $this->hidden = false;
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('banksync@banksync');
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(19, 0);
        $this->need_javascript_ajax = 0;
        $this->const = array();

        if (!isModEnabled('banksync')) {
            $conf->banksync = new stdClass();
            $conf->banksync->enabled = 0;
        }

        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = 5003011;
        $this->rights[$r][1] = 'Read bank synchronization data';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = 5003012;
        $this->rights[$r][1] = 'Import bank statements';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'import';
        $r++;

        $this->menu = array();
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu' => '',
            'type' => 'top',
            'titre' => 'BankSync',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
            'mainmenu' => 'banksync',
            'leftmenu' => '',
            'url' => '/banksync/index.php',
            'langs' => 'banksync@banksync',
            'position' => 100,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncDashboard',
            'mainmenu' => 'banksync',
            'leftmenu' => 'banksync_dashboard',
            'url' => '/banksync/index.php',
            'langs' => 'banksync@banksync',
            'position' => 100,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncTransactions',
            'mainmenu' => 'banksync',
            'leftmenu' => 'banksync_transactions',
            'url' => '/banksync/transactions.php',
            'langs' => 'banksync@banksync',
            'position' => 105,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=banksync',
            'type' => 'left',
            'titre' => 'BankSyncImport',
            'mainmenu' => 'banksync',
            'leftmenu' => 'banksync_import',
            'url' => '/banksync/import.php',
            'langs' => 'banksync@banksync',
            'position' => 110,
            'enabled' => "isModEnabled('banksync')",
            'perms' => '$user->hasRight("banksync", "import")',
            'target' => '',
            'user' => 0,
        );
    }

    public function init($options = '')
    {
        $result = $this->_load_tables('/banksync/sql/');
        if ($result < 0) {
            return -1;
        }

        $sql = array();
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
