<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Post-parse validation: reads PS_VERSION out of the staged sandbox, confirms
 * the required tables and columns are present, and flags a dump already
 * imported (same MD5). Returns a structured verdict the controller turns into
 * the staged preview screen. Pure read-only against the sandbox.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AsomValidator
{
    /** Tables that must exist in the sandbox for an import to be possible. */
    const REQUIRED_TABLES = [
        'customer', 'address', 'orders', 'order_detail', 'configuration',
    ];

    /** Required columns per table (rejecting check; missing => hard error). */
    const REQUIRED_COLUMNS = [
        'customer' => ['email', 'firstname', 'lastname', 'passwd'],
        'orders' => ['reference', 'id_customer', 'total_paid', 'date_add', 'id_carrier', 'payment'],
        'order_detail' => ['product_id', 'product_quantity', 'product_price', 'id_order'],
        'address' => ['id_address', 'firstname', 'lastname', 'address1', 'city', 'postcode', 'id_country'],
    ];

    /** @var int */
    private $idJob;
    /** @var string sandbox base incl. _DB_PREFIX_, e.g. ps_psmig_42_ */
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
     * @param string $base sandbox table base name
     *
     * @return bool
     */
    private function tableExists($base)
    {
        try {
            $name = $this->sandboxBase . $base;
            $like = str_replace('_', '\\_', $name);
            $rows = $this->db->executeS('SHOW TABLES LIKE \'' . pSQL($like) . '\'');

            return is_array($rows) && count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $base
     *
     * @return string[] lower-cased column names ([] if the table is missing)
     */
    private function columns($base)
    {
        try {
            $rows = $this->db->executeS('SHOW COLUMNS FROM `' . bqSQL($this->sandboxBase . $base) . '`');
            if (!is_array($rows)) {
                return [];
            }
            $cols = [];
            foreach ($rows as $r) {
                if (isset($r['Field'])) {
                    $cols[] = Tools::strtolower($r['Field']);
                }
            }

            return $cols;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Read PS_VERSION from the sandboxed configuration table.
     *
     * @return string '' when not found
     */
    public function detectVersion()
    {
        try {
            if (!$this->tableExists('configuration')) {
                return '';
            }
            $v = $this->db->getValue(
                'SELECT `value` FROM `' . bqSQL($this->sandboxBase . 'configuration') . '` WHERE `name` = \'PS_VERSION\''
            );

            return (string) $v;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Has this dump (by MD5) already been used by another non-failed job?
     *
     * @param string $md5
     *
     * @return int|null the other job id, or null
     */
    public function findDuplicateJob($md5)
    {
        $md5 = (string) $md5;
        if ($md5 === '') {
            return null;
        }
        try {
            $id = (int) $this->db->getValue(
                'SELECT id_asom_job FROM `' . _DB_PREFIX_ . 'asom_job`
                 WHERE dump_md5 = \'' . pSQL($md5) . '\'
                   AND id_asom_job <> ' . $this->idJob . '
                   AND status IN (\'staged\',\'mapping\',\'importing\',\'completed\')
                 ORDER BY id_asom_job ASC'
            );

            return $id ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Run the full verdict.
     *
     * @param string $dumpMd5
     *
     * @return array{
     *   version:string, version_ok:bool,
     *   errors:string[], warnings:string[],
     *   duplicate:int|null, can_proceed:bool
     * }
     */
    public function validate($dumpMd5 = '')
    {
        $errors = [];
        $warnings = [];

        // Version.
        $version = $this->detectVersion();
        $versionOk = ($version !== '' && strpos($version, '1.7.') === 0);
        if ($version === '') {
            $warnings[] = 'Nie udało się wykryć wersji PrestaShop w źródle (w zrzucie brak wartości PS_VERSION). Kontynuuj tylko wtedy, gdy ufasz źródłu.';
        } elseif (!$versionOk) {
            $warnings[] = 'Wykryta wersja PrestaShop w źródle: ' . $version . '. Moduł jest sprawdzony dla przejścia z 1.7.8.x do 9.0.x. Kontynuujesz na własne ryzyko.';
        }

        // Required tables.
        foreach (self::REQUIRED_TABLES as $t) {
            if (!$this->tableExists($t)) {
                $errors[] = 'W zrzucie brakuje wymaganej tabeli: „' . $t . '”.';
            }
        }

        // Required columns (only for tables that exist; missing table already errored).
        foreach (self::REQUIRED_COLUMNS as $table => $needed) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $have = $this->columns($table);
            foreach ($needed as $col) {
                if (!in_array(Tools::strtolower($col), $have, true)) {
                    $errors[] = 'W zrzucie brakuje wymaganej kolumny: „' . $table . '.' . $col . '”.';
                }
            }
        }

        $duplicate = $this->findDuplicateJob($dumpMd5);
        if ($duplicate !== null) {
            $warnings[] = 'Ten zrzut (ta sama suma MD5) był już użyty w zadaniu nr ' . $duplicate . '. Kontynuacja utworzy kolejną próbę tego samego importu.';
        }

        return [
            'version' => $version,
            'version_ok' => $versionOk,
            'errors' => $errors,
            'warnings' => $warnings,
            'duplicate' => $duplicate,
            'can_proceed' => count($errors) === 0,
        ];
    }
}
