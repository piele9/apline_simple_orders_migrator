<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Thin helper around the asom_mapping table: an old-id -> new-id translator
 * per job and entity type, with a recorded strategy. Used by the mapping UI
 * (products, order states, payments, carriers) and by the import pipeline to
 * resolve source ids to target ids.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AsomMapping
{
    const STRATEGIES = ['live_link', 'snapshot_only', 'auto_ean', 'fallback', 'skipped'];
    const ENTITIES = [
        'customer', 'address', 'order', 'product', 'category',
        'carrier', 'payment_module', 'order_state', 'currency', 'lang',
    ];

    /**
     * Insert or update a mapping row (unique by job + entity_type + old_id).
     *
     * @param int         $idJob
     * @param string      $entityType
     * @param string      $oldId
     * @param string|null $newId
     * @param string      $strategy
     * @param string      $note
     *
     * @return bool
     */
    public static function set($idJob, $entityType, $oldId, $newId, $strategy, $note = '')
    {
        $idJob = (int) $idJob;
        if (!in_array($entityType, self::ENTITIES, true) || !in_array($strategy, self::STRATEGIES, true)) {
            return false;
        }
        $db = Db::getInstance();
        $data = [
            'id_asom_job' => $idJob,
            'entity_type' => pSQL($entityType),
            'old_id' => pSQL((string) $oldId),
            'new_id' => ($newId === null || $newId === '') ? null : pSQL((string) $newId),
            'mapping_strategy' => pSQL($strategy),
            'note' => $note === '' ? null : pSQL(Tools::substr((string) $note, 0, 512)),
        ];

        $existing = (int) $db->getValue(
            'SELECT id_asom_mapping FROM `' . _DB_PREFIX_ . 'asom_mapping`
             WHERE id_asom_job = ' . $idJob . '
               AND entity_type = \'' . pSQL($entityType) . '\'
               AND old_id = \'' . pSQL((string) $oldId) . '\''
        );

        if ($existing) {
            return $db->update('asom_mapping', $data, 'id_asom_mapping = ' . $existing);
        }

        return $db->insert('asom_mapping', $data);
    }

    /**
     * @param int    $idJob
     * @param string $entityType
     * @param string $oldId
     *
     * @return array|null
     */
    public static function get($idJob, $entityType, $oldId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'asom_mapping`
             WHERE id_asom_job = ' . (int) $idJob . '
               AND entity_type = \'' . pSQL($entityType) . '\'
               AND old_id = \'' . pSQL((string) $oldId) . '\''
        );

        return is_array($row) && $row ? $row : null;
    }

    /**
     * All mappings of one entity type for a job, keyed by old_id.
     *
     * @param int    $idJob
     * @param string $entityType
     *
     * @return array<string,array>
     */
    public static function all($idJob, $entityType)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'asom_mapping`
             WHERE id_asom_job = ' . (int) $idJob . '
               AND entity_type = \'' . pSQL($entityType) . '\''
        );
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[(string) $r['old_id']] = $r;
            }
        }

        return $out;
    }

    /**
     * Resolve a single old id to its new id (or null when unresolved/snapshot).
     *
     * @param int    $idJob
     * @param string $entityType
     * @param string $oldId
     *
     * @return string|null
     */
    public static function resolve($idJob, $entityType, $oldId)
    {
        $row = self::get($idJob, $entityType, $oldId);
        if (!$row || !isset($row['new_id']) || $row['new_id'] === null || $row['new_id'] === '') {
            return null;
        }

        return (string) $row['new_id'];
    }

    /**
     * Count mappings of an entity type grouped by strategy.
     *
     * @param int    $idJob
     * @param string $entityType
     *
     * @return array<string,int>
     */
    public static function countByStrategy($idJob, $entityType)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT mapping_strategy, COUNT(*) c FROM `' . _DB_PREFIX_ . 'asom_mapping`
             WHERE id_asom_job = ' . (int) $idJob . '
               AND entity_type = \'' . pSQL($entityType) . '\'
             GROUP BY mapping_strategy'
        );
        $out = [];
        foreach (self::STRATEGIES as $s) {
            $out[$s] = 0;
        }
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[(string) $r['mapping_strategy']] = (int) $r['c'];
            }
        }

        return $out;
    }
}
