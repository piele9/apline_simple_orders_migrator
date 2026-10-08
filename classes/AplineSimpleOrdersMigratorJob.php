<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * ObjectModel for the asom_job table: one row per import session. The mapping
 * and log tables are handled directly through Db (no ObjectModel) because they
 * are simple insert/select workloads.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AplineSimpleOrdersMigratorJob extends ObjectModel
{
    /** @var string */
    public $name;
    /** @var string */
    public $dump_filename;
    /** @var int */
    public $dump_size_bytes;
    /** @var string */
    public $dump_md5;
    /** @var string */
    public $source_ps_version;
    /** @var string */
    public $source_db_prefix;
    /** @var string */
    public $status;
    /** @var string JSON */
    public $import_options;
    /** @var string JSON */
    public $counts_source;
    /** @var string JSON */
    public $counts_imported;
    /** @var string JSON */
    public $counts_skipped;
    /** @var string JSON */
    public $counts_failed;
    /** @var string */
    public $error_summary;
    /** @var string|null */
    public $started_at;
    /** @var string|null */
    public $finished_at;
    /** @var string */
    public $date_add;
    /** @var string */
    public $date_upd;

    /** Pipeline states, in order. Mirrors the ENUM in the DB. */
    const STATUSES = [
        'uploaded', 'parsing', 'staged', 'mapping',
        'importing', 'completed', 'failed', 'cleaned',
    ];

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'asom_job',
        'primary' => 'id_asom_job',
        'multilang' => false,
        'fields' => [
            'name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 255],
            'dump_filename' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 255],
            'dump_size_bytes' => ['type' => self::TYPE_INT],
            'dump_md5' => ['type' => self::TYPE_STRING, 'validate' => 'isMd5', 'size' => 32, 'allow_null' => true],
            'source_ps_version' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32, 'allow_null' => true],
            'source_db_prefix' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'import_options' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'allow_null' => true],
            'counts_source' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'allow_null' => true],
            'counts_imported' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'allow_null' => true],
            'counts_skipped' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'allow_null' => true],
            'counts_failed' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'allow_null' => true],
            'error_summary' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'allow_null' => true],
            'started_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'finished_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Decode a JSON column into an array, tolerating null/empty/invalid input.
     *
     * @param string $column one of the JSON-bearing property names
     *
     * @return array
     */
    public function getJson($column)
    {
        $raw = isset($this->{$column}) ? (string) $this->{$column} : '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Encode an array and store it on a JSON-bearing column (in memory only;
     * the caller still has to save()).
     *
     * @param string $column
     * @param array  $value
     */
    public function setJson($column, array $value)
    {
        $this->{$column} = json_encode($value);
    }

    /**
     * @param string $status
     *
     * @return bool whether the given status is part of the known pipeline
     */
    public static function isValidStatus($status)
    {
        return in_array($status, self::STATUSES, true);
    }
}
