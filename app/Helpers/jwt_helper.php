<?php
// app/Helpers/jwt_helper.php

if (!function_exists('base64url_encode')) {
    function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    function base64url_decode($b64)
    {
        $pad = 4 - (strlen($b64) % 4);
        if ($pad < 4)
            $b64 .= str_repeat('=', $pad);
        return base64_decode(strtr($b64, '-_', '+/'));
    }
}

/**
 * Decode and verify a simple HS256 JWT.
 * Returns payload array on success, or null on failure.
 */
if (!function_exists('jwt_decode')) {
    function jwt_decode(string $jwt): ?array
    {
        $secret = env('JWT_SECRET') ?? null;
        if (!$secret)
            return null;

        $parts = explode('.', $jwt);
        if (count($parts) !== 3)
            return null;

        list($b64Header, $b64Payload, $b64Sig) = $parts;

        $headerJson = base64url_decode($b64Header);
        $payloadJson = base64url_decode($b64Payload);
        $signature = base64url_decode($b64Sig);

        if ($headerJson === false || $payloadJson === false || $signature === false)
            return null;

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload))
            return null;
        if (($header['alg'] ?? '') !== 'HS256')
            return null;

        $signingInput = $b64Header . '.' . $b64Payload;
        $expectedSig = hash_hmac('sha256', $signingInput, $secret, true);

        // timing-safe compare
        if (!hash_equals($expectedSig, $signature))
            return null;

        // check exp
        if (isset($payload['exp']) && time() > (int) $payload['exp'])
            return null;

        return $payload;
    }

    if (!function_exists('jwt_encode')) {
    function jwt_encode(array $payload): string
    {
        $secret = env('JWT_SECRET') ?? null;
        if (!$secret) {
            throw new Exception("JWT_SECRET not defined in .env");
        }

        // Header for HS256
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        // Encode header & payload
        $b64Header  = base64url_encode(json_encode($header));
        $b64Payload = base64url_encode(json_encode($payload));

        // Create signature
        $signingInput = $b64Header . '.' . $b64Payload;
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $b64Signature = base64url_encode($signature);

        // Final JWT token
        return $b64Header . '.' . $b64Payload . '.' . $b64Signature;
    }
}

}
