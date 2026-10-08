<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Batched import engine. Replays the staged sandbox data into the native PS
 * tables, phase by phase, resolving every foreign key through asom_mapping.
 *
 * Strategy: column intersection. For each entity we copy only the columns that
 * exist in BOTH the sandbox copy and this shop's native table, drop the primary
 * key, and override the remapped foreign keys. This survives the 1.7 -> 9
 * schema drift: columns dropped in 9 are simply not copied, columns added in 9
 * take their DB default. Historical orders are inserted as snapshots (no order
 * workflow side effects), which matches the module's "transparent snapshot"
 * philosophy.
 *
 * Resumable: a cursor (phase + last source id + running counts) is stored in
 * the job's import_options['_import'] and advanced at batch boundaries.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomMapping.php';

class AsomImporter
{
    /** Ordered pipeline. Customers before addresses so links resolve. */
    const PHASES = [
        'import_customers',
        'import_addresses',
        'import_orders',
        'import_order_details',
        'import_order_history',
        'import_messages',
    ];

    /** Source primary key per phase table. */
    const PK = [
        'import_customers' => ['table' => 'customer', 'pk' => 'id_customer'],
        'import_addresses' => ['table' => 'address', 'pk' => 'id_address'],
        'import_orders' => ['table' => 'orders', 'pk' => 'id_order'],
        'import_order_details' => ['table' => 'order_detail', 'pk' => 'id_order_detail'],
        'import_order_history' => ['table' => 'order_history', 'pk' => 'id_order_history'],
        'import_messages' => ['table' => 'message', 'pk' => 'id_message'],
    ];

    /** Polskie nazwy tabel źródła w komunikatach postępu. */
    const TABLE_LABELS = [
        'customer' => 'klienci',
        'address' => 'adresy',
        'orders' => 'zamówienia',
        'order_detail' => 'pozycje zamówień',
        'order_history' => 'historia zamówień',
        'message' => 'wiadomości do zamówień',
    ];

    /** @var int */
    private $idJob;
    /** @var string */
    private $sandboxBase;
    /** @var Db */
    private $db;
    /** @var array */
    private $options;
    /** @var array native column cache */
    private $nativeCols = [];
    /** @var array sandbox column cache */
    private $sandboxCols = [];

    /**
     * @param int    $idJob
     * @param string $sandboxBase
     * @param array  $options import_options decoded
     */
    public function __construct($idJob, $sandboxBase, array $options)
    {
        $this->idJob = (int) $idJob;
        $this->sandboxBase = (string) $sandboxBase;
        $this->options = $options;
        $this->db = Db::getInstance();
    }

    // ---- Cursor -------------------------------------------------------------

    /**
     * @return array {phase:string, last_id:int, imported:array, skipped:array, failed:array}
     */
    private function loadCursor()
    {
        $raw = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $raw, true);
        $opts = is_array($opts) ? $opts : [];
        $c = isset($opts['_import']) && is_array($opts['_import']) ? $opts['_import'] : [];

        return [
            'phase' => isset($c['phase']) ? (string) $c['phase'] : self::PHASES[0],
            'last_id' => isset($c['last_id']) ? (int) $c['last_id'] : 0,
            'imported' => isset($c['imported']) && is_array($c['imported']) ? $c['imported'] : [],
            'skipped' => isset($c['skipped']) && is_array($c['skipped']) ? $c['skipped'] : [],
            'failed' => isset($c['failed']) && is_array($c['failed']) ? $c['failed'] : [],
        ];
    }

    /**
     * @param array $cursor
     */
    private function saveCursor(array $cursor)
    {
        $raw = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $raw, true);
        $opts = is_array($opts) ? $opts : [];
        $opts['_import'] = $cursor;
        $this->db->update('asom_job', ['import_options' => pSQL(json_encode($opts)), 'date_upd' => date('Y-m-d H:i:s')], 'id_asom_job = ' . $this->idJob);
    }

    /**
     * @param string $severity
     * @param string $phase
     * @param string $message
     * @param string $entityType
     * @param string $entityOldId
     */
    private function log($severity, $phase, $message, $entityType = null, $entityOldId = null)
    {
        try {
            $this->db->insert('asom_log', [
                'id_asom_job' => $this->idJob,
                'severity' => pSQL($severity),
                'phase' => pSQL($phase),
                'entity_type' => $entityType === null ? null : pSQL($entityType),
                'entity_old_id' => $entityOldId === null ? null : pSQL((string) $entityOldId),
                'message' => pSQL(Tools::substr((string) $message, 0, 4000)),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // logging must never break the import
        }
    }

    // ---- Column helpers -----------------------------------------------------

    /**
     * @param string $nativeTable native table base name (no prefix)
     *
     * @return string[] lower-cased column names
     */
    private function nativeColumns($nativeTable)
    {
        if (isset($this->nativeCols[$nativeTable])) {
            return $this->nativeCols[$nativeTable];
        }
        $cols = [];
        try {
            $rows = $this->db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . bqSQL($nativeTable) . '`');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (isset($r['Field'])) {
                        $cols[] = Tools::strtolower($r['Field']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // missing native table -> empty
        }
        $this->nativeCols[$nativeTable] = $cols;

        return $cols;
    }

    /**
     * @param string $base sandbox table base name
     *
     * @return string[] lower-cased column names
     */
    private function sandboxColumns($base)
    {
        if (isset($this->sandboxCols[$base])) {
            return $this->sandboxCols[$base];
        }
        $cols = [];
        try {
            $rows = $this->db->executeS('SHOW COLUMNS FROM `' . bqSQL($this->sandboxBase . $base) . '`');
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (isset($r['Field'])) {
                        $cols[] = Tools::strtolower($r['Field']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // missing sandbox table -> empty
        }
        $this->sandboxCols[$base] = $cols;

        return $cols;
    }

    /**
     * Build an insert payload from a source row: keep only columns present in
     * both schemas, drop the primary key, pSQL every value.
     *
     * @param array  $row         source row
     * @param string $sandboxBase sandbox base table
     * @param string $nativeTable native table
     * @param string $pk          primary key to drop
     *
     * @return array column => pSQL value
     */
    private function intersectRow(array $row, $sandboxBase, $nativeTable, $pk)
    {
        $native = $this->nativeColumns($nativeTable);
        $data = [];
        foreach ($row as $col => $val) {
            $lc = Tools::strtolower($col);
            if ($lc === Tools::strtolower($pk)) {
                continue;
            }
            if (!in_array($lc, $native, true)) {
                continue;
            }
            $data[$lc] = ($val === null) ? null : pSQL($val, true);
        }

        return $data;
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

    // ---- Public driver ------------------------------------------------------

    /**
     * Run one bounded batch and advance the cursor.
     *
     * @param int $batchSize rows to process this call
     *
     * @return array {done, phase, percent, message, imported, skipped, failed}
     */
    public function runBatch($batchSize = 50)
    {
        $cursor = $this->loadCursor();
        $phase = $cursor['phase'];

        // Skip phases disabled by import_options.
        while ($phase !== null && !$this->phaseEnabled($phase)) {
            $phase = $this->nextPhase($phase);
            $cursor['phase'] = $phase;
            $cursor['last_id'] = 0;
        }

        if ($phase === null) {
            return $this->finish($cursor);
        }

        $info = self::PK[$phase];
        if (!$this->sandboxHas($info['table'])) {
            // Nothing to import for this phase; move on.
            $phase = $this->nextPhase($phase);
            $cursor['phase'] = $phase;
            $cursor['last_id'] = 0;
            $this->saveCursor($cursor);

            return $this->progress($cursor, 'Pominięto (tej tabeli nie ma w zrzucie).');
        }

        $rows = $this->fetchBatch($info['table'], $info['pk'], $cursor['last_id'], (int) $batchSize);

        if (!$rows) {
            // Phase complete -> advance.
            $phase = $this->nextPhase($phase);
            $cursor['phase'] = $phase;
            $cursor['last_id'] = 0;
            $this->saveCursor($cursor);
            if ($phase === null) {
                return $this->finish($cursor);
            }

            return $this->progress($cursor, 'Faza zakończona.');
        }

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        $committed = false;
        try {
            $this->db->execute('START TRANSACTION');
            foreach ($rows as $row) {
                $oldId = (string) $row[$info['pk']];
                $cursor['last_id'] = (int) $row[$info['pk']];
                try {
                    $this->importOne($phase, $row, $cursor);
                } catch (\Throwable $e) {
                    $this->bump($cursor, 'failed', $phase);
                    $this->log('error', $phase, 'Nie udało się zaimportować rekordu: ' . $e->getMessage(), $info['table'], $oldId);
                }
            }
            $this->db->execute('COMMIT');
            $committed = true;
        } catch (\Throwable $e) {
            if (!$committed) {
                try {
                    $this->db->execute('ROLLBACK');
                } catch (\Throwable $e2) {
                    // ignore
                }
            }
            $this->log('error', $phase, 'Wsad nie powiódł się i został wycofany: ' . $e->getMessage(), $info['table']);
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');

        $this->saveCursor($cursor);

        return $this->progress($cursor, 'Import: ' . self::TABLE_LABELS[$info['table']] . '...');
    }

    /**
     * @param string $phase
     *
     * @return bool whether the import_options enable this phase
     */
    private function phaseEnabled($phase)
    {
        $o = $this->options;
        switch ($phase) {
            case 'import_customers':
            case 'import_orders':
            case 'import_order_details':
                return true; // core, always run
            case 'import_addresses':
                return !isset($o['import_addresses']) || $o['import_addresses'];
            case 'import_order_history':
                return !isset($o['import_order_history']) || $o['import_order_history'];
            case 'import_messages':
                return !empty($o['import_messages']);
            default:
                return false;
        }
    }

    /**
     * @param string $phase
     *
     * @return string|null next phase or null at the end
     */
    private function nextPhase($phase)
    {
        $i = array_search($phase, self::PHASES, true);
        if ($i === false || $i + 1 >= count(self::PHASES)) {
            return null;
        }

        return self::PHASES[$i + 1];
    }

    /**
     * @param string $base
     * @param string $pk
     * @param int    $afterId
     * @param int    $limit
     *
     * @return array
     */
    private function fetchBatch($base, $pk, $afterId, $limit)
    {
        $rows = $this->db->executeS(
            'SELECT * FROM `' . bqSQL($this->sandboxBase . $base) . '`
             WHERE `' . bqSQL($pk) . '` > ' . (int) $afterId . '
             ORDER BY `' . bqSQL($pk) . '` ASC
             LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : [];
    }

    // ---- Per-entity import --------------------------------------------------

    /**
     * @param string $phase
     * @param array  $row
     * @param array  $cursor by reference (counts updated)
     */
    private function importOne($phase, array $row, array &$cursor)
    {
        switch ($phase) {
            case 'import_customers':
                $this->importCustomer($row, $cursor);
                break;
            case 'import_addresses':
                $this->importAddress($row, $cursor);
                break;
            case 'import_orders':
                $this->importOrder($row, $cursor);
                break;
            case 'import_order_details':
                $this->importOrderDetail($row, $cursor);
                break;
            case 'import_order_history':
                $this->importOrderHistory($row, $cursor);
                break;
            case 'import_messages':
                $this->importMessage($row, $cursor);
                break;
        }
    }

    private function importCustomer(array $row, array &$cursor)
    {
        $oldId = (string) $row['id_customer'];
        $email = isset($row['email']) ? (string) $row['email'] : '';

        if ($email === '' || !Validate::isEmail($email)) {
            $this->bump($cursor, 'failed', 'customer');
            $this->log('warning', 'import_customers', 'Nieprawidłowy adres e-mail — klient pominięty.', 'customer', $oldId);

            return;
        }

        // Dedup by email.
        $existing = (int) $this->db->getValue('SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer` WHERE email = \'' . pSQL($email) . '\'');
        if ($existing) {
            AsomMapping::set($this->idJob, 'customer', $oldId, $existing, 'skipped', 'Istniejący klient (ten sam adres e-mail)');
            $this->bump($cursor, 'skipped', 'customer');

            return;
        }

        $data = $this->intersectRow($row, $this->sandboxBase . 'customer', 'customer', 'id_customer');

        // Force a fresh random password (customers reset it via the e-mail sent after the import).
        $data['passwd'] = pSQL($this->randomHash());
        $now = date('Y-m-d H:i:s');
        if (in_array('date_add', $this->nativeColumns('customer'), true)) {
            $data['date_add'] = isset($data['date_add']) ? $data['date_add'] : pSQL($now);
        }
        if (in_array('date_upd', $this->nativeColumns('customer'), true)) {
            $data['date_upd'] = pSQL($now);
        }
        $this->forceShopColumns($data, 'customer');

        if (!$this->db->insert('customer', $data)) {
            throw new \RuntimeException('nie udało się zapisać klienta');
        }
        $newId = (int) $this->db->Insert_ID();

        // Default customer group link.
        $this->linkCustomerGroup($newId);

        AsomMapping::set($this->idJob, 'customer', $oldId, $newId, 'live_link', 'Zaimportowano');
        $this->bump($cursor, 'imported', 'customer');
    }

    private function importAddress(array $row, array &$cursor)
    {
        $oldId = (string) $row['id_address'];

        // Idempotency: a mapping row already recorded for this old_id means a
        // previous run of this job already inserted (or decided on) this
        // address - never insert it twice.
        if (AsomMapping::get($this->idJob, 'address', $oldId)) {
            $this->bump($cursor, 'skipped', 'address');

            return;
        }

        $data = $this->intersectRow($row, $this->sandboxBase . 'address', 'address', 'id_address');

        // Re-link to the imported customer when the source address had one.
        if (isset($row['id_customer']) && (int) $row['id_customer'] > 0) {
            $newCust = AsomMapping::resolve($this->idJob, 'customer', (string) $row['id_customer']);
            if ($newCust === null) {
                // Customer not imported (e.g. filtered) -> skip orphan address.
                $this->bump($cursor, 'skipped', 'address');

                return;
            }
            $data['id_customer'] = (int) $newCust;
        }
        if (in_array('deleted', $this->nativeColumns('address'), true)) {
            // Keep the source flag: an address the customer deleted stays archived
            // (PrestaShop still shows it in the orders that used it).
            $data['deleted'] = isset($row['deleted']) ? (int) $row['deleted'] : 0;
        }
        $now = date('Y-m-d H:i:s');
        if (in_array('date_add', $this->nativeColumns('address'), true) && empty($data['date_add'])) {
            $data['date_add'] = pSQL($now);
        }
        if (in_array('date_upd', $this->nativeColumns('address'), true)) {
            $data['date_upd'] = pSQL($now);
        }

        if (!$this->db->insert('address', $data)) {
            throw new \RuntimeException('nie udało się zapisać adresu');
        }
        $newId = (int) $this->db->Insert_ID();
        AsomMapping::set($this->idJob, 'address', $oldId, $newId, 'live_link', 'Zaimportowano');
        $this->bump($cursor, 'imported', 'address');
    }

    private function importOrder(array $row, array &$cursor)
    {
        $oldId = (string) $row['id_order'];
        $reference = isset($row['reference']) ? (string) $row['reference'] : '';

        // Dedup by reference.
        if ($reference !== '' && !empty($this->options['skip_duplicate_orders'])) {
            $exists = (int) $this->db->getValue('SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE reference = \'' . pSQL($reference) . '\'');
            if ($exists) {
                AsomMapping::set($this->idJob, 'order', $oldId, $exists, 'skipped', 'Istniejące zamówienie (ta sama referencja)');
                $this->bump($cursor, 'skipped', 'order');

                return;
            }
        }

        $data = $this->intersectRow($row, $this->sandboxBase . 'orders', 'orders', 'id_order');

        // Resolve customer.
        $newCust = AsomMapping::resolve($this->idJob, 'customer', isset($row['id_customer']) ? (string) $row['id_customer'] : '0');
        if ($newCust === null) {
            $this->bump($cursor, 'failed', 'order');
            $this->log('warning', 'import_orders', 'Brak zmapowanego klienta — zamówienie pominięte.', 'order', $oldId);

            return;
        }
        $data['id_customer'] = (int) $newCust;

        // Resolve addresses.
        $this->applyMapped($data, $row, 'id_address_delivery', 'address');
        $this->applyMapped($data, $row, 'id_address_invoice', 'address');

        // Resolve currency / lang / carrier / state.
        $this->applyMapped($data, $row, 'id_currency', 'currency');
        $this->applyMapped($data, $row, 'id_lang', 'lang');
        $this->applyMapped($data, $row, 'id_carrier', 'carrier');
        $this->applyMappedState($data, $row);

        // Resolve payment module name.
        if (isset($row['module']) && in_array('module', $this->nativeColumns('orders'), true)) {
            $payNew = AsomMapping::resolve($this->idJob, 'payment_module', (string) $row['module']);
            if ($payNew !== null) {
                $data['module'] = pSQL($payNew);
            }
        }

        $this->forceShopColumns($data, 'orders');
        $now = date('Y-m-d H:i:s');
        if (in_array('date_upd', $this->nativeColumns('orders'), true)) {
            $data['date_upd'] = pSQL($now);
        }
        // Ensure a secure_key exists (NOT NULL in PS).
        if (in_array('secure_key', $this->nativeColumns('orders'), true) && empty($data['secure_key'])) {
            $data['secure_key'] = pSQL(md5(uniqid((string) $this->idJob, true)));
        }

        if (!$this->db->insert('orders', $data)) {
            throw new \RuntimeException('nie udało się zapisać zamówienia');
        }
        $newId = (int) $this->db->Insert_ID();
        AsomMapping::set($this->idJob, 'order', $oldId, $newId, 'live_link', 'Zaimportowano');
        $this->bump($cursor, 'imported', 'order');
    }

    private function importOrderDetail(array $row, array &$cursor)
    {
        $oldId = (string) $row['id_order_detail'];
        $oldOrderId = isset($row['id_order']) ? (string) $row['id_order'] : '0';
        $newOrder = $this->resolveFreshOrder($oldOrderId);
        if ($newOrder === null) {
            // Parent order not imported this job (skipped duplicate, failed,
            // or already carries lines from a previous run of this job) -> skip.
            $this->bump($cursor, 'skipped', 'order_detail');

            return;
        }

        $data = $this->intersectRow($row, $this->sandboxBase . 'order_detail', 'order_detail', 'id_order_detail');
        $data['id_order'] = (int) $newOrder;

        // Resolve product: live link -> new id, snapshot/unresolved -> 0.
        if (isset($row['product_id'])) {
            $map = AsomMapping::get($this->idJob, 'product', (string) $row['product_id']);
            $newProduct = 0;
            if ($map && in_array((string) $map['mapping_strategy'], ['live_link', 'auto_ean'], true)
                && $map['new_id'] !== null && $map['new_id'] !== '') {
                $newProduct = (int) $map['new_id'];
            }
            if (in_array('product_id', $this->nativeColumns('order_detail'), true)) {
                $data['product_id'] = $newProduct;
            }
        }

        if (!$this->db->insert('order_detail', $data)) {
            throw new \RuntimeException('nie udało się zapisać pozycji zamówienia');
        }
        $this->bump($cursor, 'imported', 'order_detail');
    }

    private function importOrderHistory(array $row, array &$cursor)
    {
        $newOrder = $this->resolveFreshOrder(isset($row['id_order']) ? (string) $row['id_order'] : '0');
        if ($newOrder === null) {
            $this->bump($cursor, 'skipped', 'order_history');

            return;
        }
        $data = $this->intersectRow($row, $this->sandboxBase . 'order_history', 'order_history', 'id_order_history');
        $data['id_order'] = (int) $newOrder;
        $this->applyMappedState($data, $row);

        if (!$this->db->insert('order_history', $data)) {
            throw new \RuntimeException('nie udało się zapisać historii zamówienia');
        }
        $this->bump($cursor, 'imported', 'order_history');
    }

    private function importMessage(array $row, array &$cursor)
    {
        // Only order-attached messages (standalone customer-service out of scope).
        if (!isset($row['id_order']) || (int) $row['id_order'] <= 0) {
            $this->bump($cursor, 'skipped', 'message');

            return;
        }
        $newOrder = $this->resolveFreshOrder((string) $row['id_order']);
        if ($newOrder === null) {
            $this->bump($cursor, 'skipped', 'message');

            return;
        }
        $data = $this->intersectRow($row, $this->sandboxBase . 'message', 'message', 'id_message');
        $data['id_order'] = (int) $newOrder;

        // Re-link customer if present.
        if (isset($row['id_customer']) && (int) $row['id_customer'] > 0) {
            $nc = AsomMapping::resolve($this->idJob, 'customer', (string) $row['id_customer']);
            if ($nc !== null && in_array('id_customer', $this->nativeColumns('message'), true)) {
                $data['id_customer'] = (int) $nc;
            }
        }

        if (!$this->db->insert('message', $data)) {
            throw new \RuntimeException('nie udało się zapisać wiadomości do zamówienia');
        }
        $this->bump($cursor, 'imported', 'message');
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * Resolve an order's new id, but only when THIS job actually inserted it
     * (mapping_strategy 'live_link'). Order children (order_detail,
     * order_history, message) have no natural-key dedup of their own, so
     * re-running against an order that was 'skipped' (already existed) must
     * never re-add its lines/history/messages - that would duplicate them
     * onto the pre-existing order on every re-run.
     *
     * @param string $oldOrderId
     *
     * @return int|null
     */
    private function resolveFreshOrder($oldOrderId)
    {
        $map = AsomMapping::get($this->idJob, 'order', $oldOrderId);
        if (!$map || $map['mapping_strategy'] !== 'live_link' || $map['new_id'] === null || $map['new_id'] === '') {
            return null;
        }

        return (int) $map['new_id'];
    }

    /**
     * Override a column with its mapped target id when both the column exists
     * natively and a mapping resolves.
     *
     * @param array  $data       insert payload (by ref)
     * @param array  $row        source row
     * @param string $column     fk column name
     * @param string $entityType mapping entity type
     */
    private function applyMapped(array &$data, array $row, $column, $entityType)
    {
        if (!isset($row[$column])) {
            return;
        }
        // Only override columns that survived the intersection (i.e. exist
        // natively); otherwise there is nothing to set.
        if (!array_key_exists(Tools::strtolower($column), $data)) {
            return;
        }
        $new = AsomMapping::resolve($this->idJob, $entityType, (string) $row[$column]);
        if ($new !== null) {
            $data[Tools::strtolower($column)] = (int) $new;
        }
    }

    /**
     * Resolve current_state / id_order_state from the order_state mapping.
     *
     * @param array $data
     * @param array $row
     */
    private function applyMappedState(array &$data, array $row)
    {
        foreach (['current_state', 'id_order_state'] as $col) {
            if (array_key_exists($col, $data) && isset($row[$col]) && (int) $row[$col] > 0) {
                $new = AsomMapping::resolve($this->idJob, 'order_state', (string) $row[$col]);
                if ($new !== null) {
                    $data[$col] = (int) $new;
                }
            }
        }
    }

    /**
     * Pin shop columns to the current context shop (avoids dangling id_shop).
     *
     * @param array  $data
     * @param string $nativeTable
     */
    private function forceShopColumns(array &$data, $nativeTable)
    {
        $native = $this->nativeColumns($nativeTable);
        $idShop = (int) Context::getContext()->shop->id;
        $idShopGroup = (int) Context::getContext()->shop->id_shop_group;
        if (in_array('id_shop', $native, true)) {
            $data['id_shop'] = $idShop ? $idShop : 1;
        }
        if (in_array('id_shop_group', $native, true)) {
            $data['id_shop_group'] = $idShopGroup ? $idShopGroup : 1;
        }
    }

    /**
     * Link a new customer to the shop's default customer group (and as their
     * default group) when those tables/columns exist.
     *
     * @param int $idCustomer
     */
    private function linkCustomerGroup($idCustomer)
    {
        try {
            $idGroup = (int) Configuration::get('PS_CUSTOMER_GROUP');
            if (!$idGroup) {
                $idGroup = 3;
            }
            $exists = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_group` WHERE id_customer = ' . (int) $idCustomer . ' AND id_group = ' . $idGroup);
            if (!$exists) {
                $this->db->insert('customer_group', ['id_customer' => (int) $idCustomer, 'id_group' => $idGroup]);
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * @return string a valid bcrypt hash of a random secret
     */
    private function randomHash()
    {
        $secret = base64_encode(md5(uniqid((string) mt_getrandmax(), true)) . uniqid('', true));
        if (function_exists('password_hash')) {
            return password_hash($secret, PASSWORD_BCRYPT);
        }

        return md5(_COOKIE_KEY_ . $secret);
    }

    /**
     * @param array  $cursor by ref
     * @param string $bucket imported|skipped|failed
     * @param string $entity
     */
    private function bump(array &$cursor, $bucket, $entity)
    {
        if (!isset($cursor[$bucket][$entity])) {
            $cursor[$bucket][$entity] = 0;
        }
        ++$cursor[$bucket][$entity];
    }

    /**
     * @param array  $cursor
     * @param string $message
     *
     * @return array
     */
    private function progress(array $cursor, $message)
    {
        $phaseIndex = array_search($cursor['phase'], self::PHASES, true);
        $total = count(self::PHASES);
        $percent = ($phaseIndex === false) ? 99 : (int) floor((($phaseIndex) / $total) * 100);
        if ($percent > 99) {
            $percent = 99;
        }

        return [
            'success' => true,
            'done' => false,
            'phase' => $cursor['phase'],
            'percent' => $percent,
            'message' => $message,
            'imported' => $cursor['imported'],
            'skipped' => $cursor['skipped'],
            'failed' => $cursor['failed'],
        ];
    }

    /**
     * Finalize the job: write the count summaries and mark it completed. The
     * password-reset emails run as a separate follow-up phase.
     *
     * @param array $cursor
     *
     * @return array
     */
    private function finish(array $cursor)
    {
        $this->bumpCartAutoIncrement();
        $this->db->update('asom_job', [
            'status' => 'completed',
            'finished_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
            'counts_imported' => pSQL(json_encode($cursor['imported'])),
            'counts_skipped' => pSQL(json_encode($cursor['skipped'])),
            'counts_failed' => pSQL(json_encode($cursor['failed'])),
        ], 'id_asom_job = ' . $this->idJob);

        $this->log('info', 'finish', 'Import zakończony. Zaimportowano: ' . json_encode($cursor['imported']));

        return [
            'success' => true,
            'done' => true,
            'phase' => 'completed',
            'percent' => 100,
            'message' => 'Import zakończony.',
            'imported' => $cursor['imported'],
            'skipped' => $cursor['skipped'],
            'failed' => $cursor['failed'],
        ];
    }

    /**
     * Imported orders and messages keep their source id_cart (carts are not
     * migrated). Move the cart counter past the highest such id, otherwise new
     * carts reuse them: checkout fails with "an order has already been placed
     * using this cart" and the order message step picks up a migrated message.
     */
    private function bumpCartAutoIncrement()
    {
        $max = 0;
        foreach (['orders', 'message', 'cart'] as $table) {
            $max = max($max, (int) $this->db->getValue('SELECT MAX(id_cart) FROM `' . _DB_PREFIX_ . $table . '`', false));
        }
        // executeS: getRow() would append LIMIT 1, which SHOW TABLE STATUS rejects
        $status = $this->db->executeS('SHOW TABLE STATUS LIKE \'' . pSQL(_DB_PREFIX_ . 'cart') . '\'', true, false);
        $current = (int) ($status[0]['Auto_increment'] ?? 0);
        if ($current > $max) {
            return;
        }
        $this->db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'cart` AUTO_INCREMENT = ' . ($max + 1));
        $this->log('info', 'finish', 'Licznik AUTO_INCREMENT koszyków przesunięty z ' . $current . ' na ' . ($max + 1) . ' (poza zaimportowane wartości id_cart).');
    }
}
