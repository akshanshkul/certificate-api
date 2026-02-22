<?php
namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use Config\Services;
use App\Models\ClientApiKeyModel;
use App\Models\ClientModel;
use App\Libraries\AuthContext;

class ApiKeyAuth implements FilterInterface
{
    protected $keyModel;
    protected $clientModel;

    public function __construct()
    {
        $this->keyModel = new ClientApiKeyModel();
        $this->clientModel = new ClientModel();
    }

    /**
     * Before filter - validate API key + secret
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // 1) Extract key + secret from headers
        $apiKey = $request->getHeaderLine('x-api-key') ?: null;
        $apiSecret = $request->getHeaderLine('x-api-secret') ?: null;

        // Also support Authorization: ApiKey key:secret
        if (!$apiKey && !$apiSecret) {
            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader && preg_match('/ApiKey\s+(\S+)/i', $authHeader, $m)) {
                // expecting key:secret (colon-separated)
                if (strpos($m[1], ':') !== false) {
                    [$apiKey, $apiSecret] = explode(':', $m[1], 2);
                } else {
                    $apiKey = $m[1]; // secret may be missing
                }
            }
        }

        if (empty($apiKey) || empty($apiSecret)) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'status' => false, 'message' => 'API credentials required']);
        }

        // 2) Lookup API key row
        $keyRow = $this->keyModel->findByApiKey($apiKey);
        if (!$keyRow) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'status' => false, 'message' => 'Invalid API key']);
        }

        // 3) Check revoked
        if (!empty($keyRow['revoked'])) {
            return Services::response()
                ->setStatusCode(403)
                ->setJSON(['success' => 0, 'status' => false, 'message' => 'API key revoked']);
        }

        // 4) Check expiry (expires_at NULL => never expires)
        if (!empty($keyRow['expires_at'])) {
            $now = time();
            $expiresAt = strtotime($keyRow['expires_at']);
            if ($expiresAt <= $now) {
                return Services::response()
                    ->setStatusCode(401)
                    ->setJSON(['success' => 0, 'status' => false, 'message' => 'API key expired']);
            }
        }

        // 5) Verify secret (compare SHA256 hash)
        $providedHash = hash('sha256', $apiSecret);
        if (!hash_equals($keyRow['secret_hash'], $providedHash)) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'status' => false, 'message' => 'Invalid API secret']);
        }

        // 6) Load client and check status
        $client = $this->clientModel->find($keyRow['client_id'] ?? 0);
        if (!$client) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'status' => false, 'message' => 'Client not found']);
        }
        if (($client['status'] ?? '') !== 'active') {
            return Services::response()
                ->setStatusCode(403)
                ->setJSON(['success' => 0, 'status' => false, 'message' => 'Client inactive']);
        }

        $remoteIp = $this->getClientIpFromAllowedIps($request, (string) ($client['allowed_ips'] ?? ''));

        // 7) Check client allowed_ips if set (comma separated list of IPs or CIDRs)
        $allowedIps = trim((string) ($client['allowed_ips'] ?? ''));
        if ($allowedIps !== '') {
            if (!$this->ipAllowed($allowedIps, $remoteIp)) {
                return Services::response()
                    ->setStatusCode(403)
                    ->setJSON(['success' => 0, 'status' => false, 'message' => 'IP not allowed', 'ip' => $remoteIp]);
            }
        }

        // 8) Update last_used_at for key (non-critical, ignore failures)
        try {
            $this->keyModel->markUsed($apiKey);
        } catch (\Throwable $e) {
            // intentionally ignore DB errors here
        }
        // 9) Attach client + key info to request for controllers    
        AuthContext::setClient(
            $client
        );

        AuthContext::setClientKey([
            'id' => $keyRow['id'],
            'label' => $keyRow['label'],
            'api_key' => $keyRow['api_key'],
            'expires_at' => $keyRow['expires_at'],
            'last_used_at' => $keyRow['last_used_at'],
        ]);
        // allow request to continue
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No special after behaviour
    }

    /**
     * Check whether an IP is allowed given a comma separated list of ips/CIDRs.
     *
     * @param string $allowedList e.g. "1.2.3.4, 10.0.0.0/8"
     * @param string $ip
     * @return bool
     */
    protected function ipAllowed(string $allowedList, string $ip): bool
    {
        $items = array_filter(array_map('trim', explode(',', $allowedList)));
        if (empty($items)) {
            return true;
        }

        foreach ($items as $entry) {
            if ($entry === '')
                continue;
            // exact match
            if ($entry === $ip) {
                return true;
            }

            // CIDR support
            if (strpos($entry, '/') !== false) {
                if ($this->cidrMatch($ip, $entry)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range.
     *
     * @param string $ip
     * @param string $cidr e.g. "103.21.55.0/24"
     * @return bool
     */
    protected function cidrMatch(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr);
        $mask = (int) $mask;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1)) & 0xFFFFFFFF;
        return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
    }


    /**
     * Determine client IP safely based on client's allowed_ips.
     * Logic:
     * - If remoteIp is inside allowed_ips → trust forwarded headers (X-Forwarded-For, X-Real-IP)
     * - Otherwise → return remoteIp directly
     */
    protected function getClientIpFromAllowedIps(RequestInterface $request, string $allowedIps): string
    {
        $remoteIp = $request->getIPAddress();

        // Convert comma-separated allowed IPs into array
        $allowedList = array_filter(array_map('trim', explode(',', $allowedIps)));

        // If no allowedIps configured, return remoteIp
        if (empty($allowedList)) {
            return $remoteIp;
        }

        // If remote IP is NOT inside allowed list → do NOT trust forwarded headers
        if (!$this->ipAllowed($allowedIps, $remoteIp)) {
            return $remoteIp;
        }

        // Remote IP is trusted → now check forwarded headers
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff) {
            $parts = array_map('trim', explode(',', $xff));
            if (!empty($parts[0])) {
                return $parts[0]; // original client IP
            }
        }

        $realIp = $request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return $realIp;
        }

        $cfIp = $request->getHeaderLine('CF-Connecting-IP');
        if (!empty($cfIp)) {
            return $cfIp;
        }

        // No forwarded headers → return remote
        return $remoteIp;
    }


}
