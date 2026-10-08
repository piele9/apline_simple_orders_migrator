<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Product mapping engine (the 3-layer pipeline):
 *   Layer 1 - auto match by EAN against this shop's ps_product.ean13
 *   Layer 2 - manual live link chosen by the admin (search helper here)
 *   Layer 3 - snapshot-only fallback for whatever stays unresolved
 *
 * Reads the distinct products that appear in the job's sandbox order_detail and
 * tracks each decision in asom_mapping (entity_type = 'product').
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomMapping.php';

class AsomProductMapper
{
    /** @var int */
    private $idJob;
    /** @var string */
    private $sandboxBase;
    /** @var Db */
    private $db;

    /**
     * @param int    $idJob
     * @param string $sandboxBase  e.g. _DB_PREFIX_ . 'psmig_' . id . '_'
     */
    public function __construct($idJob, $sandboxBase)
    {
        $this->idJob = (int) $idJob;
        $this->sandboxBase = (string) $sandboxBase;
        $this->db = Db::getInstance();
    }

    /**
     * @param string $base
     * @param string $col
     *
     * @return bool whether a sandbox table has a given column
     */
    private function hasColumn($base, $col)
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

    /**
     * Distinct products that occur in the sandbox order_detail, with the units
     * sold, the snapshot name and EAN, plus the current mapping decision.
     *
     * @return array[] each: old_id, name, ean, sold, lines, strategy, new_id,
     *                 matched_name, note
     */
    public function getProducts()
    {
        $table = bqSQL($this->sandboxBase . 'order_detail');
        $hasEan = $this->hasColumn('order_detail', 'product_ean13');
        $hasName = $this->hasColumn('order_detail', 'product_name');

        $eanSel = $hasEan ? 'MAX(`product_ean13`)' : '\'\'';
        $nameSel = $hasName ? 'MAX(`product_name`)' : '\'\'';

        $rows = $this->db->executeS(
            'SELECT `product_id` AS old_id,
                    ' . $nameSel . ' AS name,
                    ' . $eanSel . ' AS ean,
                    SUM(`product_quantity`) AS sold,
                    COUNT(*) AS `lines`
             FROM `' . $table . '`
             GROUP BY `product_id`
             ORDER BY sold DESC'
        );
        if (!is_array($rows)) {
            return [];
        }

        $mappings = AsomMapping::all($this->idJob, 'product');

        $out = [];
        foreach ($rows as $r) {
            $oldId = (string) $r['old_id'];
            $map = isset($mappings[$oldId]) ? $mappings[$oldId] : null;
            $strategy = $map ? (string) $map['mapping_strategy'] : '';
            $newId = $map && $map['new_id'] !== null ? (string) $map['new_id'] : '';

            $out[] = [
                'old_id' => $oldId,
                'name' => (string) $r['name'],
                'ean' => (string) $r['ean'],
                'sold' => (int) $r['sold'],
                'lines' => (int) $r['lines'],
                'strategy' => $strategy,
                'new_id' => $newId,
                'matched_name' => ($newId !== '' ? $this->productName((int) $newId) : ''),
                'note' => $map && isset($map['note']) ? (string) $map['note'] : '',
            ];
        }

        return $out;
    }

    /**
     * Layer 1: match every still-undecided product that has an EAN against the
     * target shop's ps_product.ean13. Existing manual / snapshot decisions are
     * never overwritten.
     *
     * @return int number of products auto-matched in this pass
     */
    public function autoMatchByEan()
    {
        $matched = 0;
        foreach ($this->getProducts() as $p) {
            // Skip products the admin already decided (manual or snapshot).
            if (in_array($p['strategy'], ['live_link', 'snapshot_only'], true)) {
                continue;
            }
            $ean = trim((string) $p['ean']);
            if ($ean === '') {
                continue;
            }
            // Db::getRow()/getValue() already append their own "LIMIT 1"; an
            // explicit one here would double up ("LIMIT 1 LIMIT 1") and fail.
            $idProduct = (int) $this->db->getValue(
                'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE ean13 = \'' . pSQL($ean) . '\''
            );
            if ($idProduct > 0) {
                AsomMapping::set($this->idJob, 'product', $p['old_id'], $idProduct, 'auto_ean', 'Dopasowano po EAN ' . $ean);
                ++$matched;
            }
        }

        return $matched;
    }

    /**
     * Layer 3: set snapshot_only for every product still without a final
     * decision. Returns how many were defaulted.
     *
     * @return int
     */
    public function applySnapshotFallback()
    {
        $count = 0;
        foreach ($this->getProducts() as $p) {
            if (in_array($p['strategy'], ['live_link', 'snapshot_only', 'auto_ean'], true)) {
                continue;
            }
            AsomMapping::set($this->idJob, 'product', $p['old_id'], null, 'snapshot_only', 'Migawka (brak powiązania z produktem)');
            ++$count;
        }

        return $count;
    }

    /**
     * Number of products still needing a decision.
     *
     * @return int
     */
    public function unresolvedCount()
    {
        $n = 0;
        foreach ($this->getProducts() as $p) {
            if (!in_array($p['strategy'], ['live_link', 'snapshot_only', 'auto_ean'], true)) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Resolve a target product name for display (default lang).
     *
     * @param int $idProduct
     *
     * @return string
     */
    private function productName($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return '';
        }
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        // Db::getRow()/getValue() already append their own "LIMIT 1"; an
        // explicit one here would double up ("LIMIT 1 LIMIT 1") and fail.
        $name = $this->db->getValue(
            'SELECT name FROM `' . _DB_PREFIX_ . 'product_lang`
             WHERE id_product = ' . $idProduct . ' AND id_lang = ' . $idLang
        );

        return $name !== false ? (string) $name : '';
    }

    /**
     * Layer 2 helper: search this shop's products by name / reference / EAN /
     * MPN for the manual-link autocomplete.
     *
     * @param string $query
     * @param int    $limit
     *
     * @return array[] each: id_product, name, reference, ean13
     */
    public function searchProducts($query, $limit = 10)
    {
        $query = trim((string) $query);
        if ($query === '') {
            return [];
        }
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $like = '%' . pSQL($query) . '%';
        $limit = (int) $limit;

        $rows = $this->db->executeS(
            'SELECT p.id_product, pl.name, p.reference, p.ean13
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                 ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . '
             WHERE pl.name LIKE \'' . $like . '\'
                OR p.reference LIKE \'' . $like . '\'
                OR p.ean13 LIKE \'' . $like . '\'
                OR p.mpn LIKE \'' . $like . '\'
             GROUP BY p.id_product
             ORDER BY pl.name ASC
             LIMIT ' . $limit
        );

        return is_array($rows) ? $rows : [];
    }
}
