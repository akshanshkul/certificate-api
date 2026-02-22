<?php
namespace App\Controllers\Api;

use App\Models\ClientApiKeyModel;
use CodeIgniter\Controller;
use App\Models\ClientModel;
use DateTime;
class Client extends Controller
{

    protected $clientModel;
    public function __construct()
    {
        $this->clientModel = new ClientModel();
    }

    /**
     * Create a client (only clients table)
     * POST /api/clients/create
     * Payload: { name, contact_email?, contact_phone?, website_url?, callback_url?, plan?, allowed_ips?, notes? }
     */
    public function createClient()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        if (empty($data['name'])) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'name is required'
            ], 422);
        }

        if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'invalid contact_email'
            ], 422);
        }

        $allowedPlans = ['free', 'basic', 'pro', 'enterprise'];
        $plan = in_array($data['plan'] ?? 'free', $allowedPlans) ? $data['plan'] : 'free';


        $clientRow = [
            'name' => $data['name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'callback_url' => $data['callback_url'] ?? null,
            'plan' => $plan,
            'status' => 'active',
            'allowed_ips' => $data['allowed_ips'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $clientId = $this->clientModel->insert($clientRow);

        if (!$clientId) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Failed to create client'
            ], 500);
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Client created',
            'client' => [
                'id' => $clientId,
                'name' => $clientRow['name'],
                'contact_email' => $clientRow['contact_email'],
                'plan' => $clientRow['plan'],
                'created_at' => $clientRow['created_at'],
            ]
        ], 201);
    }

    /**
     * Generate API key for an existing client
     * POST /api/clients/{id}/keys
     * Payload: { label?, expires_at? }  (expires_at format: YYYY-mm-dd HH:ii:ss or null)
     */
    public function generateApiKey($clientId = null)
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $clientId = $clientId ?? ($data['client_id'] ?? null);

        if (empty($clientId) || !is_numeric($clientId)) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'client_id is required'
            ], 200);
        }

        // Check client exists and active
        $client = $this->clientModel->find($clientId);
        if (!$client) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Client not found'
            ], 200);
        }
        if ($client['status'] !== 'active') {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Client is not active'
            ], 200);
        }
        $clientApiKeyModel = new ClientApiKeyModel();

        $client = $clientApiKeyModel->findByClientId($clientId);
        if ($client) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Client Api key already exists'
            ], 200);
        }
        // Generate api_key and secret
        try {
            $apiKey = 'cli_' . bin2hex(random_bytes(8));   // public id
            $secret = bin2hex(random_bytes(32));          // plain secret (return once)
        } catch (\Exception $e) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Failed to generate credentials'
            ], 200);
        }

        $secretHash = hash('sha256', $secret);
        $date = new DateTime();
        $date->modify('+1 year');
        $keyRow = [
            'client_id' => $clientId,
            'label' => $data['label'] ?? 'primary-key',
            'api_key' => $apiKey,
            'secret_hash' => $secretHash,
            'revoked' => 0,
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : $date->format('Y-m-d H:i:s'),
            'last_used_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $data['created_by'] ?? 'system'
        ];
        $keyId = $clientApiKeyModel->insert($keyRow);

        if (!$keyId) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Failed to create API key'
            ], 200);
        }

        // Return api_key and plain secret once
        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'API key generated',
            'api_key' => [
                'id' => $keyId,
                'api_key' => $apiKey,
                'secret' => $secret,     // show once
                'label' => $keyRow['label'],
                'expires_at' => $keyRow['expires_at']
            ]
        ], 201);
    }

    /**
     * Revoke / delete API key (soft delete)
     * POST /api/clients/keys/revoke
     * Payload: { api_key }  OR route param: revoke/{api_key}
     */
    public function revokeApiKey($apiKey = null)
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $apiKey = $apiKey ?? ($data['api_key'] ?? null);

        if (empty($apiKey)) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'api_key is required'
            ], 422);
        }
        $clientApiKeyModel = new ClientApiKeyModel();
        $updated = $clientApiKeyModel->revokeKey($apiKey);

        if ($updated) {
            return $this->respond([
                'success' => 1,
                'status' => true,
                'message' => 'API key revoked'
            ]);
        }

        return $this->respond([
            'success' => 0,
            'status' => false,
            'message' => 'API key not found or failed to revoke'
        ], 404);
    }

    /**
     * Deactivate client (set status = disabled)
     * POST /api/clients/{id}/deactivate
     * Payload optional: { reason? }
     */
    public function deactivateClient($clientId = null)
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $clientId = $clientId ?? ($data['client_id'] ?? null);

        if (empty($clientId) || !is_numeric($clientId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'client_id required'], 422);
        }

        $client = $this->clientModel->find($clientId);
        if (!$client) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Client not found'], 404);
        }

        $updated = $this->clientModel->update($clientId, [
            'status' => 'disabled',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($updated) {
            return $this->respond(['success' => 1, 'status' => true, 'message' => 'Client deactivated']);
        }

        return $this->respond(['success' => 0, 'status' => false, 'message' => 'Failed to deactivate client'], 500);
    }

    /**
     * Activate client (set status = active)
     * POST /api/clients/{id}/activate
     */
    public function activateClient($clientId = null)
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $clientId = $clientId ?? ($data['client_id'] ?? null);

        if (empty($clientId) || !is_numeric($clientId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'client_id required'], 422);
        }

        $client = $this->clientModel->find($clientId);
        if (!$client) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Client not found'], 404);
        }

        $updated = $this->clientModel->update($clientId, [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($updated) {
            return $this->respond(['success' => 1, 'status' => true, 'message' => 'Client activated']);
        }

        return $this->respond(['success' => 0, 'status' => false, 'message' => 'Failed to activate client'], 500);
    }

    /**
     * List API keys for a client (safe)
     * POST /api/clients/{id}/keys
     * Payload: { client_id } or pass as param
     */
    public function listKeys($clientId = null)
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $clientId = $clientId ?? ($data['client_id'] ?? null);

        if (empty($clientId) || !is_numeric($clientId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'client_id required'], 422);
        }
        $clientApiKeyModel = new ClientApiKeyModel();
        $keys = $clientApiKeyModel->listByClient($clientId);
        return $this->respond([
            'success' => 1,
            'status' => true,
            'keys' => $keys
        ]);
    }

    /**
     * List all clients
     * GET /api/clients/list
     */
    public function listClients()
    {
        $clients = $this->clientModel->findAll();

        return $this->respond([
            'success' => 1,
            'status' => true,
            'clients' => $clients
        ]);
    }

    /**
     * Respond in JSON format
     */
    protected function respond($data, $code = 200)
    {
        return $this->response
            ->setStatusCode($code)
            ->setJSON($data);
    }
}
