<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Reference-entity mapping: order states, payment modules, carriers,
 * currencies and languages. Each source entity (from the sandbox) is mapped to
 * a target entity in this shop with an auto-suggestion the admin can override,
 * plus a global fallback. Currencies and languages must resolve by ISO code or
 * the import is blocked (you cannot invent money or a locale).
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomMapping.php';

class AsomRefMapper
{
    /** @var int */
    private $idJob;
    /** @var string */
    private $sandboxBase;
    /** @var Db */
    private $db;

    /**
     * @param int    $idJob
     * @param string $sandboxBase
     */
    public function __construct($idJob, $sandboxBase)
    {
        $this->idJob = (int) $idJob;
        $this->sandboxBase = (string) $sandboxBase;
        $this->db = Db::getInstance();
    }

    /**
     * @param string $base
     *
     * @return bool
     */
    private function sandboxHas($base)
    {
        try {
            $like = str_replace('_', '\\_', $this->sandboxBase . $base);
            $rows = $this->db->executeS('SHOW TABLES LIKE \'' . pSQL($like) . '\'');

            return is_array($rows) && count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $base
     * @param string $col
     *
     * @return bool
     */
    private function sandboxHasColumn($base, $col)
    {
        try {
            $rows = $this->db->executeS('SHOW COLUMNS FROM `' . bqSQL($this->sandboxBase . $base) . '`');
            if (!is_array($rows)) {
                return false;
            }
            foreach ($rows as $r) {
                if (isset($r['Field']) && Tools::strtolower($r['Field']) === Tools::strtolower($col)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return false;
    }

    // ---- Source readers -----------------------------------------------------

    /**
     * Source order states (id => name). Falls back to the distinct states used
     * by orders when the order_state table was not in the dump.
     *
     * @return array<string,string>
     */
    public function sourceOrderStates()
    {
        $out = [];
        if ($this->sandboxHas('order_state')) {
            $nameJoin = $this->sandboxHas('order_state_lang')
                ? 'LEFT JOIN `' . bqSQL($this->sandboxBase . 'order_state_lang') . '` l ON l.id_order_state = s.id_order_state'
                : '';
            $nameSel = $this->sandboxHas('order_state_lang') ? 'MAX(l.name)' : '\'\'';
            $rows = $this->db->executeS(
                'SELECT s.id_order_state AS id, ' . $nameSel . ' AS name
                 FROM `' . bqSQL($this->sandboxBase . 'order_state') . '` s ' . $nameJoin . '
                 GROUP BY s.id_order_state'
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['id']] = (string) $r['name'];
                }
            }
        }
        if (!$out && $this->sandboxHas('orders') && $this->sandboxHasColumn('orders', 'current_state')) {
            $rows = $this->db->executeS('SELECT DISTINCT current_state AS id FROM `' . bqSQL($this->sandboxBase . 'orders') . '` WHERE current_state > 0');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['id']] = '';
                }
            }
        }

        return $out;
    }

    /**
     * Distinct payment module identifiers used by the source orders.
     *
     * @return array<string,string> moduleName => display payment label
     */
    public function sourcePayments()
    {
        $out = [];
        if (!$this->sandboxHas('orders')) {
            return $out;
        }
        $hasModule = $this->sandboxHasColumn('orders', 'module');
        $hasPayment = $this->sandboxHasColumn('orders', 'payment');
        $key = $hasModule ? 'module' : ($hasPayment ? 'payment' : null);
        if ($key === null) {
            return $out;
        }
        $labelSel = $hasPayment ? 'MAX(`payment`)' : 'MAX(`' . bqSQL($key) . '`)';
        $rows = $this->db->executeS(
            'SELECT `' . bqSQL($key) . '` AS id, ' . $labelSel . ' AS label
             FROM `' . bqSQL($this->sandboxBase . 'orders') . '`
             WHERE `' . bqSQL($key) . '` <> \'\'
             GROUP BY `' . bqSQL($key) . '`'
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[(string) $r['id']] = (string) $r['label'];
            }
        }

        return $out;
    }

    /**
     * Source carriers (id => name).
     *
     * @return array<string,string>
     */
    public function sourceCarriers()
    {
        $out = [];
        if ($this->sandboxHas('carrier')) {
            $nameCol = $this->sandboxHasColumn('carrier', 'name') ? 'MAX(c.name)' : '\'\'';
            $rows = $this->db->executeS(
                'SELECT c.id_carrier AS id, ' . $nameCol . ' AS name
                 FROM `' . bqSQL($this->sandboxBase . 'carrier') . '` c
                 GROUP BY c.id_carrier'
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['id']] = (string) $r['name'];
                }
            }
        }
        if (!$out && $this->sandboxHas('orders') && $this->sandboxHasColumn('orders', 'id_carrier')) {
            $rows = $this->db->executeS('SELECT DISTINCT id_carrier AS id FROM `' . bqSQL($this->sandboxBase . 'orders') . '` WHERE id_carrier > 0');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['id']] = '';
                }
            }
        }

        return $out;
    }

    /**
     * Source currencies as id => iso_code (only those usable for mapping).
     *
     * @return array<string,string>
     */
    public function sourceCurrencies()
    {
        $out = [];
        if ($this->sandboxHas('currency') && $this->sandboxHasColumn('currency', 'iso_code')) {
            $rows = $this->db->executeS('SELECT id_currency AS id, iso_code FROM `' . bqSQL($this->sandboxBase . 'currency') . '`');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['id']] = Tools::strtoupper((string) $r['iso_code']);
                }
            }
        }

        return $out;
    }

    /**
     * Source languages as id => iso_code.
     *
     * @return array<string,string>
     */
    public function sourceLangs()
    {
        $out = [];
        if ($this->sandboxHas('lang') && $this->sandboxHasColumn('lang', 'iso_code')) {
            $rows = $this->db->executeS('SELECT id_lang AS id, iso_code FROM `' . bqSQL($this->sandboxBase . 'lang') . '`');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['id']] = Tools::strtolower((string) $r['iso_code']);
                }
            }
        }

        return $out;
    }

    // ---- Target option lists ------------------------------------------------

    /**
     * @return array<int,string> id_order_state => name (this shop)
     */
    public function targetOrderStates()
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $rows = $this->db->executeS(
            'SELECT s.id_order_state AS id, sl.name
             FROM `' . _DB_PREFIX_ . 'order_state` s
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` sl
                 ON sl.id_order_state = s.id_order_state AND sl.id_lang = ' . $idLang . '
             WHERE s.deleted = 0
             ORDER BY s.id_order_state ASC'
        );

        return $this->keyById($rows, 'id', 'name');
    }

    /**
     * @return array<string,string> moduleName => display name (installed payments)
     */
    public function targetPayments()
    {
        $out = [];
        // Installed payment modules: those registered on the paymentOptions /
        // payment hooks. Fall back to all installed modules if hook lookup
        // returns nothing (keeps the dropdown usable across versions).
        $rows = $this->db->executeS(
            'SELECT DISTINCT m.name, m.name AS display
             FROM `' . _DB_PREFIX_ . 'module` m
             INNER JOIN `' . _DB_PREFIX_ . 'hook_module` hm ON hm.id_module = m.id_module
             INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON h.id_hook = hm.id_hook
             WHERE h.name IN (\'paymentOptions\',\'displayPayment\',\'paymentReturn\')
             ORDER BY m.name ASC'
        );
        if (is_array($rows) && $rows) {
            foreach ($rows as $r) {
                $out[(string) $r['name']] = (string) $r['display'];
            }
        }
        if (!$out) {
            $rows = $this->db->executeS('SELECT name FROM `' . _DB_PREFIX_ . 'module` ORDER BY name ASC');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $out[(string) $r['name']] = (string) $r['name'];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int,string> id_carrier => name (active, non-deleted)
     */
    public function targetCarriers()
    {
        $rows = $this->db->executeS(
            'SELECT id_carrier AS id, name FROM `' . _DB_PREFIX_ . 'carrier`
             WHERE deleted = 0 ORDER BY name ASC'
        );

        return $this->keyById($rows, 'id', 'name');
    }

    /**
     * @return array<int,string> id_currency => iso_code (this shop)
     */
    public function targetCurrencies()
    {
        $rows = $this->db->executeS('SELECT id_currency AS id, iso_code FROM `' . _DB_PREFIX_ . 'currency` WHERE deleted = 0');

        return $this->keyById($rows, 'id', 'iso_code');
    }

    /**
     * @return array<int,string> id_lang => iso_code (this shop)
     */
    public function targetLangs()
    {
        $rows = $this->db->executeS('SELECT id_lang AS id, iso_code FROM `' . _DB_PREFIX_ . 'lang` WHERE active = 1');

        return $this->keyById($rows, 'id', 'iso_code');
    }

    /**
     * @param array|false $rows
     * @param string      $idKey
     * @param string      $valKey
     *
     * @return array
     */
    private function keyById($rows, $idKey, $valKey)
    {
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[(string) $r[$idKey]] = (string) $r[$valKey];
            }
        }

        return $out;
    }

    // ---- Auto-suggestions + first-entry seeding -----------------------------

    /**
     * Seed sensible default mappings the first time the mapping phase is shown
     * (only for entity types that have no mappings yet). Idempotent.
     */
    public function ensureAutoSuggestions()
    {
        $this->seedOrderStates();
        $this->seedPayments();
        $this->seedCarriers();
        $this->seedCurrencies();
        $this->seedLangs();
    }

    private function seedOrderStates()
    {
        if (AsomMapping::all($this->idJob, 'order_state')) {
            return;
        }
        $target = $this->targetOrderStates();
        foreach ($this->sourceOrderStates() as $old => $name) {
            // Default PS states share ids across 1.7 and 9: keep 1:1 when the
            // id exists in this shop, otherwise fall back to state 1.
            if (isset($target[$old])) {
                AsomMapping::set($this->idJob, 'order_state', $old, (int) $old, 'live_link', 'Domyślny status 1:1');
            } else {
                AsomMapping::set($this->idJob, 'order_state', $old, 1, 'fallback', 'Nieznany status źródłowy → status nr 1');
            }
        }
    }

    private function seedPayments()
    {
        if (AsomMapping::all($this->idJob, 'payment_module')) {
            return;
        }
        $target = $this->targetPayments();
        foreach ($this->sourcePayments() as $old => $label) {
            if (isset($target[$old])) {
                AsomMapping::set($this->idJob, 'payment_module', $old, $old, 'live_link', 'Ta sama nazwa modułu');
            } else {
                AsomMapping::set($this->idJob, 'payment_module', $old, 'ps_wirepayment', 'fallback', 'Brak dopasowania płatności → przelew bankowy');
            }
        }
    }

    private function seedCarriers()
    {
        if (AsomMapping::all($this->idJob, 'carrier')) {
            return;
        }
        $target = $this->targetCarriers();
        $fallback = $this->firstActiveCarrier();
        foreach ($this->sourceCarriers() as $old => $name) {
            $match = $this->matchByName($target, $name);
            if ($match !== null) {
                AsomMapping::set($this->idJob, 'carrier', $old, $match, 'live_link', 'Dopasowano po nazwie');
            } elseif ($fallback) {
                AsomMapping::set($this->idJob, 'carrier', $old, $fallback, 'fallback', 'Brak dopasowania przewoźnika → pierwszy przewoźnik');
            }
        }
    }

    private function seedCurrencies()
    {
        if (AsomMapping::all($this->idJob, 'currency')) {
            return;
        }
        $target = array_flip($this->targetCurrencies()); // iso => id
        foreach ($this->sourceCurrencies() as $old => $iso) {
            if (isset($target[$iso])) {
                AsomMapping::set($this->idJob, 'currency', $old, (int) $target[$iso], 'live_link', 'Dopasowano po kodzie ISO ' . $iso);
            } else {
                AsomMapping::set($this->idJob, 'currency', $old, null, 'skipped', 'Waluta ' . $iso . ' nie jest skonfigurowana w tym sklepie');
            }
        }
    }

    private function seedLangs()
    {
        if (AsomMapping::all($this->idJob, 'lang')) {
            return;
        }
        $target = array_flip($this->targetLangs()); // iso => id
        $default = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach ($this->sourceLangs() as $old => $iso) {
            if (isset($target[$iso])) {
                AsomMapping::set($this->idJob, 'lang', $old, (int) $target[$iso], 'live_link', 'Dopasowano po kodzie ISO ' . $iso);
            } else {
                AsomMapping::set($this->idJob, 'lang', $old, $default, 'fallback', 'Brak języka ' . $iso . ' → język domyślny');
            }
        }
    }

    /**
     * @return int|null id of the first non-deleted carrier
     */
    private function firstActiveCarrier()
    {
        $id = (int) $this->db->getValue('SELECT id_carrier FROM `' . _DB_PREFIX_ . 'carrier` WHERE deleted = 0 ORDER BY id_carrier ASC');

        return $id ? $id : null;
    }

    /**
     * Case-insensitive name match against a target option list.
     *
     * @param array<string,string> $target id => name
     * @param string               $name
     *
     * @return string|null matching id
     */
    private function matchByName($target, $name)
    {
        $name = Tools::strtolower(trim((string) $name));
        if ($name === '') {
            return null;
        }
        foreach ($target as $id => $tName) {
            if (Tools::strtolower(trim($tName)) === $name) {
                return (string) $id;
            }
        }

        return null;
    }

    // ---- Save + readiness ---------------------------------------------------

    /**
     * Persist a posted set of decisions for one entity type.
     *
     * @param string                $entityType
     * @param array<string,string>  $pairs old_id => new_id
     */
    public function saveDecisions($entityType, array $pairs)
    {
        foreach ($pairs as $old => $new) {
            $old = (string) $old;
            $new = (string) $new;
            $strategy = ($new === '' ? 'skipped' : 'live_link');
            AsomMapping::set($this->idJob, $entityType, $old, $new === '' ? null : $new, $strategy, 'Ustawione przez administratora');
        }
    }

    /**
     * Currencies / languages actually used by the source orders that have no
     * resolved target. These block the import (you cannot import an order in a
     * currency the shop does not have).
     *
     * @return string[] human-readable blocking issues
     */
    public function getBlockingIssues()
    {
        $issues = [];

        // Currencies used by orders.
        $usedCurrencies = $this->distinctOrderColumn('id_currency');
        $curMap = AsomMapping::all($this->idJob, 'currency');
        $srcCur = $this->sourceCurrencies();
        foreach ($usedCurrencies as $cid) {
            $resolved = isset($curMap[$cid]) && $curMap[$cid]['new_id'] !== null && $curMap[$cid]['new_id'] !== '';
            if (!$resolved) {
                $iso = isset($srcCur[$cid]) ? $srcCur[$cid] : ('#' . $cid);
                $issues[] = 'Waluta ' . $iso . ' występuje w zamówieniach, ale nie jest skonfigurowana w tym sklepie. Dodaj ją w Międzynarodowe > Lokalizacja > Waluty, a potem otwórz mapowanie ponownie.';
            }
        }

        return $issues;
    }

    /**
     * @param string $col
     *
     * @return string[] distinct non-zero values of orders.<col>
     */
    private function distinctOrderColumn($col)
    {
        $out = [];
        if (!$this->sandboxHas('orders') || !$this->sandboxHasColumn('orders', $col)) {
            return $out;
        }
        $rows = $this->db->executeS('SELECT DISTINCT `' . bqSQL($col) . '` AS v FROM `' . bqSQL($this->sandboxBase . 'orders') . '` WHERE `' . bqSQL($col) . '` > 0');
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = (string) $r['v'];
            }
        }

        return $out;
    }
}
