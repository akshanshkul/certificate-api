<?php
namespace App\Models;

use CodeIgniter\Model;

class ClientApiKeyModel extends Model
{
    protected $table      = 'client_api_keys';
    protected $primaryKey = 'id';

    protected $returnType       = 'array';
    protected $useSoftDeletes   = false; // using revoke instead of delete

    protected $allowedFields = [
        'client_id',
        'label',
        'api_key',
        'secret_hash',
        'revoked',
        'expires_at',
        'last_used_at',
        'created_at',
        'created_by'
    ];

    protected $useTimestamps = false;

    /**
     * Find API key row by api_key value
     */
    public function findByApiKey(string $apiKey)
    {
        return $this->where('api_key', $apiKey)->first();
    }

    public function findByClientId($clientId) {
         return $this->where('client_id', $clientId)->first();
    }

    /**
     * Revoke an API key (set revoked = 1)
     */
    public function revokeKey(string $apiKey)
    {
        return $this->where('api_key', $apiKey)
                    ->set(['revoked' => 1])
                    ->update();
    }

    /**
     * List all API keys for a specific client ID
     */
    public function listByClient(int $clientId)
    {
        return $this->where('client_id', $clientId)
                    ->orderBy('id', 'DESC')
                    ->findAll();
    }

    /**
     * Mark an API key as used (update last_used_at)
     */
    public function markUsed(string $apiKey)
    {
        return $this->where('api_key', $apiKey)
                    ->set(['last_used_at' => date('Y-m-d H:i:s')])
                    ->update();
    }
}
