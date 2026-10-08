<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Streaming SQL dump parser. Reads a mysqldump .sql file line by line (never
 * loading the whole file into memory), detects the source DB prefix, and
 * replays the CREATE TABLE / INSERT statements of a whitelist of tables into
 * per-job sandbox tables named <_DB_PREFIX_>psmig_<job_id>_<table>.
 *
 * Parsing is resumable: each call to parseChunk() processes whole statements
 * until a time or byte budget is reached, then persists a byte-offset cursor
 * (always at a statement boundary) so the next AJAX call continues from there.
 *
 * Assumptions (true for phpMyAdmin / mysqldump output, which is the supported
 * source): newlines inside data are escaped (\n), so a statement-terminating
 * ";" always appears at the end of its physical line; INSERT statements are
 * one statement per line (possibly extended with many rows); CREATE TABLE
 * spans multiple lines ending in ") ENGINE=...;". The PS table prefix contains
 * no underscore (ps_, pr_, a0wp_, ...).
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AsomDumpParser
{
    /** Tables copied into the sandbox. Order is irrelevant. */
    const TABLES = [
        'customer', 'address', 'orders', 'order_detail', 'order_history',
        'message', 'configuration', 'product', 'product_lang',
        'order_state', 'order_state_lang', 'carrier', 'carrier_lang', 'module',
        'currency', 'lang', 'customer_group', 'group_lang', 'emailsubscription',
    ];

    /** Sandbox table (base name) => counts_source key. */
    const COUNT_MAP = [
        'customer' => 'customers',
        'address' => 'addresses',
        'orders' => 'orders',
        'order_detail' => 'order_details',
        'order_history' => 'order_history',
        'message' => 'messages',
        'product' => 'products',
        'emailsubscription' => 'newsletter_subscriptions',
    ];

    /** Hard guard against a runaway statement buffer (heuristic failure). */
    const MAX_STATEMENT_BYTES = 67108864; // 64 MB

    /** @var int */
    private $idJob;
    /** @var string */
    private $dumpPath;
    /** @var string sandbox prefix incl. _DB_PREFIX_, e.g. ps_psmig_42_ */
    private $sandboxBase;
    /** @var Db */
    private $db;

    /**
     * @param int    $idJob
     * @param string $dumpPath  absolute path to the .sql file
     * @param string $sandboxBase  e.g. _DB_PREFIX_ . 'psmig_' . $idJob . '_'
     */
    public function __construct($idJob, $dumpPath, $sandboxBase)
    {
        $this->idJob = (int) $idJob;
        $this->dumpPath = (string) $dumpPath;
        $this->sandboxBase = (string) $sandboxBase;
        $this->db = Db::getInstance();
    }

    /**
     * Load the parse cursor stored in the job's import_options['_parse'].
     *
     * @return array{offset:int,prefix:string,locked:bool,created:array}
     */
    private function loadState()
    {
        $row = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $row, true);
        $opts = is_array($opts) ? $opts : [];
        $p = isset($opts['_parse']) && is_array($opts['_parse']) ? $opts['_parse'] : [];

        return [
            'offset' => isset($p['offset']) ? (int) $p['offset'] : 0,
            'prefix' => isset($p['prefix']) ? (string) $p['prefix'] : '',
            'locked' => !empty($p['locked']),
            'created' => isset($p['created']) && is_array($p['created']) ? $p['created'] : [],
        ];
    }

    /**
     * Persist the parse cursor back into import_options['_parse'].
     *
     * @param array $state
     */
    private function saveState(array $state)
    {
        $row = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $row, true);
        $opts = is_array($opts) ? $opts : [];
        $opts['_parse'] = [
            'offset' => (int) $state['offset'],
            'prefix' => (string) $state['prefix'],
            'locked' => !empty($state['locked']),
            'created' => array_values(array_unique($state['created'])),
        ];
        $this->db->update('asom_job', ['import_options' => pSQL(json_encode($opts))], 'id_asom_job = ' . $this->idJob);
    }

    /**
     * Remove the transient parse cursor once staging is complete, leaving
     * import_options clean for the user's import choices.
     */
    private function clearState()
    {
        $row = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $row, true);
        $opts = is_array($opts) ? $opts : [];
        unset($opts['_parse']);
        $this->db->update('asom_job', ['import_options' => pSQL(json_encode($opts))], 'id_asom_job = ' . $this->idJob);
    }

    /**
     * Append a row to asom_log. Best effort: logging must never abort parsing.
     *
     * @param string $severity
     * @param string $message
     * @param string $entityType
     */
    private function log($severity, $message, $entityType = null)
    {
        try {
            $this->db->insert('asom_log', [
                'id_asom_job' => $this->idJob,
                'severity' => pSQL($severity),
                'phase' => 'parse_dump',
                'entity_type' => $entityType === null ? null : pSQL($entityType),
                'entity_old_id' => null,
                'message' => pSQL(Tools::substr((string) $message, 0, 4000)),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Swallow: a logging failure is not a parsing failure.
        }
    }

    /**
     * @return int total dump size in bytes (0 if unknown)
     */
    public function getTotalBytes()
    {
        $size = @filesize($this->dumpPath);

        return $size === false ? 0 : (int) $size;
    }

    /**
     * Process one bounded slice of the dump.
     *
     * @param float $maxSeconds wall-clock budget for this call
     * @param int   $maxBytes   byte budget for this call
     *
     * @return array {done:bool, offset:int, total:int, percent:int, message:string}
     *
     * @throws \RuntimeException when the file cannot be opened
     */
    public function parseChunk($maxSeconds = 8.0, $maxBytes = 8388608)
    {
        $total = $this->getTotalBytes();
        $state = $this->loadState();

        $fh = @fopen($this->dumpPath, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Nie można otworzyć pliku zrzutu: ' . $this->dumpPath);
        }
        if ($state['offset'] > 0) {
            @fseek($fh, $state['offset']);
        }

        $start = microtime(true);
        $startOffset = $state['offset'];
        $safeOffset = $state['offset'];
        $stmt = '';

        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }

            $trimmedRight = rtrim($line, "\r\n");
            $ltrim = ltrim($trimmedRight);

            // Skip noise lines only when we are not mid-statement.
            if ($stmt === '') {
                if ($ltrim === ''
                    || strpos($ltrim, '--') === 0
                    || strpos($ltrim, '/*') === 0
                    || stripos($ltrim, 'LOCK TABLES') === 0
                    || stripos($ltrim, 'UNLOCK TABLES') === 0
                    || stripos($ltrim, 'DROP TABLE') === 0
                    || stripos($ltrim, 'SET ') === 0
                ) {
                    $safeOffset = ftell($fh);
                    continue;
                }
            }

            $stmt .= ($stmt === '' ? '' : "\n") . $trimmedRight;

            if (strlen($stmt) > self::MAX_STATEMENT_BYTES) {
                $this->log('error', 'Instrukcja przekroczyła limit rozmiaru i została pominięta w pobliżu offsetu ' . $safeOffset . '.');
                $stmt = '';
                $safeOffset = ftell($fh);
                continue;
            }

            // A statement ends when its physical line ends with ";".
            if (substr(rtrim($stmt), -1) !== ';') {
                continue;
            }

            $this->dispatchStatement($stmt, $state);
            $stmt = '';
            $safeOffset = ftell($fh);

            // Budget checks only at statement boundaries (safe resume points).
            if ((microtime(true) - $start) >= $maxSeconds
                || ($safeOffset - $startOffset) >= $maxBytes) {
                break;
            }
        }

        $atEof = feof($fh);
        @fclose($fh);

        $state['offset'] = $safeOffset;
        $this->saveState($state);

        if ($atEof) {
            $this->finalize($state);

            return [
                'done' => true,
                'offset' => $safeOffset,
                'total' => $total,
                'percent' => 100,
                'message' => 'Analiza zakończona.',
            ];
        }

        $percent = ($total > 0) ? (int) floor(($safeOffset / $total) * 100) : 0;
        if ($percent > 99) {
            $percent = 99;
        }

        return [
            'done' => false,
            'offset' => $safeOffset,
            'total' => $total,
            'percent' => $percent,
            'message' => 'Analiza zrzutu (' . $this->humanBytes($safeOffset) . ' z ' . $this->humanBytes($total) . ')...',
        ];
    }

    /**
     * Route a complete statement to CREATE or INSERT handling. Anything else
     * (or any table outside the whitelist) is ignored.
     *
     * @param string $stmt
     * @param array  $state passed by reference so prefix/created persist
     */
    private function dispatchStatement($stmt, array &$state)
    {
        $head = ltrim($stmt);

        if (stripos($head, 'CREATE TABLE') === 0) {
            $name = $this->extractTableName($head);
            if ($name === null) {
                return;
            }
            $this->lockPrefixFromName($name, $state);
            $base = $this->baseTableName($name, $state['prefix']);
            if ($base === null || !in_array($base, self::TABLES, true)) {
                return;
            }
            $this->createSandboxTable($stmt, $name, $base, $state);

            return;
        }

        if (stripos($head, 'INSERT INTO') === 0) {
            $name = $this->extractTableName($head);
            if ($name === null) {
                return;
            }
            $base = $this->baseTableName($name, $state['prefix']);
            if ($base === null || !in_array($base, self::TABLES, true)) {
                return;
            }
            // Only insert into sandbox tables we actually created.
            if (!in_array($base, $state['created'], true)) {
                return;
            }
            $this->runInsert($stmt, $name, $base);
        }
    }

    /**
     * Pull the first backtick-quoted (or bare) table name out of a CREATE/INSERT.
     *
     * @param string $head
     *
     * @return string|null
     */
    private function extractTableName($head)
    {
        if (preg_match('/^(?:CREATE\s+TABLE|INSERT\s+INTO)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $head, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Lock the source prefix the first time a CREATE TABLE name ends with a
     * known PS table suffix. Tables are tried longest-first so order_state_lang
     * wins over order_state, product_lang over product, etc.
     *
     * @param string $name
     * @param array  $state
     */
    private function lockPrefixFromName($name, array &$state)
    {
        if ($state['locked']) {
            return;
        }
        $tables = self::TABLES;
        usort($tables, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($tables as $t) {
            if (preg_match('/^(.+_)' . preg_quote($t, '/') . '$/', $name, $m)) {
                $state['prefix'] = $m[1];
                $state['locked'] = true;
                $this->log('info', 'Wykryty prefiks tabel źródła: ' . $state['prefix']);

                return;
            }
        }
    }

    /**
     * Strip the locked prefix off a table name, returning the base name.
     *
     * @param string $name
     * @param string $prefix
     *
     * @return string|null null when the name does not carry the prefix
     */
    private function baseTableName($name, $prefix)
    {
        if ($prefix === '') {
            return null;
        }
        if (strpos($name, $prefix) !== 0) {
            return null;
        }

        return substr($name, strlen($prefix));
    }

    /**
     * Rewrite a CREATE TABLE for the sandbox: swap the table name, drop foreign
     * key constraints (the sandbox needs no referential integrity and would
     * otherwise reference non-existent tables), then execute it.
     *
     * @param string $stmt
     * @param string $sourceName
     * @param string $base
     * @param array  $state
     */
    private function createSandboxTable($stmt, $sourceName, $base, array &$state)
    {
        $target = $this->sandboxBase . $base;

        // Swap only the table name token right after CREATE TABLE.
        $sql = preg_replace(
            '/^(\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`?' . preg_quote($sourceName, '/') . '`?/i',
            '$1`' . $target . '`',
            $stmt,
            1
        );

        // Remove foreign-key constraint lines and any leftover dangling commas.
        $lines = explode("\n", $sql);
        $kept = [];
        foreach ($lines as $l) {
            $lt = ltrim($l);
            if (stripos($lt, 'CONSTRAINT') === 0 || stripos($lt, 'FOREIGN KEY') === 0) {
                continue;
            }
            $kept[] = $l;
        }
        $sql = implode("\n", $kept);
        // Fix a trailing comma left before the closing parenthesis.
        $sql = preg_replace('/,(\s*\n\s*\))/', '$1', $sql);

        try {
            $this->db->execute('DROP TABLE IF EXISTS `' . bqSQL($target) . '`');
            $this->db->execute($sql);
            if (!in_array($base, $state['created'], true)) {
                $state['created'][] = $base;
            }
        } catch (\Throwable $e) {
            $this->log('error', 'Nie udało się utworzyć tabeli-piaskownicy ' . $base . ': ' . $e->getMessage(), $base);
        }
    }

    /**
     * Rewrite an INSERT to target the sandbox table and execute it verbatim
     * otherwise (column lists and values are structurally identical).
     *
     * @param string $stmt
     * @param string $sourceName
     * @param string $base
     */
    private function runInsert($stmt, $sourceName, $base)
    {
        $target = $this->sandboxBase . $base;
        $sql = preg_replace(
            '/^(\s*INSERT\s+INTO\s+)`?' . preg_quote($sourceName, '/') . '`?/i',
            '$1`' . $target . '`',
            $stmt,
            1
        );

        try {
            $this->db->execute($sql, false);
        } catch (\Throwable $e) {
            $this->log('warning', 'Nie udało się wstawić paczki rekordów do tabeli ' . $base . ': ' . $e->getMessage(), $base);
        }
    }

    /**
     * EOF reached: count rows per countable sandbox table, write counts_source,
     * clear the cursor and move the job to "staged".
     *
     * @param array $state
     */
    private function finalize(array $state)
    {
        $counts = [];
        foreach (self::COUNT_MAP as $base => $key) {
            if (!in_array($base, $state['created'], true)) {
                $counts[$key] = 0;
                continue;
            }
            try {
                $n = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . bqSQL($this->sandboxBase . $base) . '`');
            } catch (\Throwable $e) {
                $n = 0;
            }
            $counts[$key] = $n;
        }

        $this->db->update('asom_job', [
            'counts_source' => pSQL(json_encode($counts)),
            'status' => 'staged',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_asom_job = ' . $this->idJob);

        $this->clearState();
        $this->log('info', 'Wczytywanie do piaskownicy zakończone: ' . json_encode($counts));
    }

    /**
     * @param int $bytes
     *
     * @return string
     */
    private function humanBytes($bytes)
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return round($bytes, ($i > 0 ? 1 : 0)) . ' ' . $units[$i];
    }
}
