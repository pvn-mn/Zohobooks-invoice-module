<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Local cache of Zoho data (zb_cache). Kept in the database rather than the CI
 * cache so an expired copy can still be served when Zoho is unreachable.
 */
class ZbCacheModel extends Model
{
    protected $table            = 'zb_cache';
    protected $primaryKey       = 'cache_key';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['cache_key', 'payload', 'fetched_at'];

    /**
     * Cached payload, or null when missing or older than $ttl seconds ($ttl null = any age).
     */
    public function getPayload(string $key, ?int $ttl): mixed
    {
        $row = $this->find($key);
        if (! $row) {
            return null;
        }
        if ($ttl !== null && time() - (int) $row['fetched_at'] >= $ttl) {
            return null;
        }

        return json_decode($row['payload'], true);
    }

    public function putPayload(string $key, mixed $data): void
    {
        $this->builder()->replace([
            'cache_key'  => $key,
            'payload'    => json_encode($data, JSON_UNESCAPED_UNICODE),
            'fetched_at' => time(),
        ]);
    }
}
