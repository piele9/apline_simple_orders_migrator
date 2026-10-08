<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Aktualizacja do 1.1.0: polska nazwa zakładki panelu (nazwy zakładek są
 * zapisane w bazie, więc zmiana tekstu w kodzie ich nie zmienia). Moduł nie
 * dodaje w tej wersji nowych kluczy konfiguracji, hooków ani tabel; ustawienia
 * ogólne (ASOM_*), zadania importu, mapowania i logi zostają bez zmian.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param apline_simple_orders_migrator $module
 *
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    $idTab = (int) Tab::getIdFromClassName($module::ADMIN_CONTROLLER);

    if ($idTab) {
        $tab = new Tab($idTab);
    } else {
        // Zakładki brakuje (ktoś ją usunął): odtwarzamy ją tak jak install().
        $tab = new Tab();
        $tab->class_name = $module::ADMIN_CONTROLLER;
        $tab->module = $module->name;
        $tab->active = 1;
        $tab->id_parent = -1;
    }

    foreach (Language::getLanguages(false) as $language) {
        $tab->name[$language['id_lang']] = $module::TAB_NAME;
    }

    return (bool) ($idTab ? $tab->update() : $tab->add());
}
