<?php
/**
 * Push Notifications Plugin - Web Push Sender
 *
 * Pure PHP implementation of the Web Push protocol using openssl.
 * Handles VAPID authentication, payload encryption, and HTTP delivery.
 * No external libraries or Composer dependencies required.
 *
 * Requires: PHP 7.3+ (openssl_pkey_derive), openssl extension, curl extension.
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

class WebPush {

    private $vapidPublicKey;   // Raw 65 bytes (uncompressed EC point)
    private $vapidPrivateKey;  // Raw 32 bytes (EC scalar d)
    private $vapidSubject;     // mailto: or https: URL

    /**
     * @param string $publicKeyB64Url  Base64url-encoded VAPID public key (65 bytes uncompressed)
     * @param string $privateKeyB64Url Base64url-encoded VAPID private key (32 bytes)
     * @param string $subject          VAPID subject (mailto: or https:)
     */
    function __construct($publicKeyB64Url, $privateKeyB64Url, $subject) {
        $this->vapidPublicKey  = self::base64UrlDecode($publicKeyB64Url);
        $this->vapidPrivateKey = self::base64UrlDecode($privateKeyB64Url);
        $this->vapidSubject    = $subject;
    }

    /**
     * Send a push notification to a single subscription.
     *
     * @param string $endpoint     Push service endpoint URL
     * @param string $p256dhB64Url Client's P-256 public key (base64url)
     * @param string $authB64Url   Client's auth secret (base64url, 16 bytes)
     * @param string $payload      JSON payload string
     * @param string $encoding     Content encoding: 'aes128gcm' (default) or 'aesgcm'
     * @return true|string  True on success, 'gone' if subscription expired, or error message
     */
    function send($endpoint, $p256dhB64Url, $authB64Url, $payload, $encoding = 'aes128gcm') {

        $userPublicKey = self::base64UrlDecode($p256dhB64Url);
        $userAuth = self::base64UrlDecode($authB64Url);

        // Generate VAPID Authorization header
        $vapidHeaders = $this->createVapidHeaders($endpoint);
        if (!$vapidHeaders)
            return 'Failed to create VAPID headers';

        // Encrypt payload
        $encrypted = $this->encryptPayload($payload, $userPublicKey, $userAuth, $encoding);
        if (!$encrypted)
            return 'Failed to encrypt payload';

        // Build HTTP headers
        $headers = array(
            'TTL: 86400',
            'Urgency: high',
        );

        if ($encoding === 'aes128gcm') {
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'Content-Encoding: aes128gcm';
            $headers[] = sprintf('Authorization: vapid t=%s, k=%s',
                $vapidHeaders['token'], $vapidHeaders['key']);
        } else {
            // aesgcm encoding (legacy)
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'Content-Encoding: aesgcm';
            $headers[] = sprintf('Authorization: WebPush %s', $vapidHeaders['token']);
            $headers[] = sprintf('Crypto-Key: p256ecdsa=%s;dh=%s',
                $vapidHeaders['key'], $encrypted['serverPublicKeyB64']);
            $headers[] = sprintf('Encryption: salt=%s', $encrypted['saltB64']);
        }

        // Send via curl
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted['body'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError)
            return 'cURL error: ' . $curlError;

        // 201 = Created (success for most push services)
        // 200 = OK (some services)
        if ($httpCode >= 200 && $httpCode < 300)
            return true;

        // 404 or 410 = subscription expired / not found
        if ($httpCode == 404 || $httpCode == 410)
            return 'gone';

        // 429 = rate limited
        if ($httpCode == 429)
            return 'rate_limited';

        return sprintf('HTTP %d: %s', $httpCode, substr($response, 0, 200));
    }

    /**
     * Create VAPID Authorization headers (JWT token + public key).
     */
    private function createVapidHeaders($endpoint) {
        $parsed = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port']))
            $audience .= ':' . $parsed['port'];

        $expiry = time() + 43200; // 12 hours

        $token = $this->createJWT($audience, $this->vapidSubject, $expiry);
        if (!$token)
            return false;

        return array(
            'token' => $token,
            'key'   => self::base64UrlEncode($this->vapidPublicKey),
        );
    }

    /**
     * Create a signed ES256 JWT for VAPID authentication.
     */
    private function createJWT($audience, $subject, $expiry) {
        $header = self::base64UrlEncode(json_encode(array(
            'typ' => 'JWT',
            'alg' => 'ES256',
        )));

        $payload = self::base64UrlEncode(json_encode(array(
            'aud' => $audience,
            'exp' => $expiry,
            'sub' => $subject,
        )));

        $signingInput = $header . '.' . $payload;

        // Build PEM private key from raw components
        $pem = $this->buildECPrivateKeyPEM($this->vapidPrivateKey, $this->vapidPublicKey);
        if (!$pem)
            return false;

        $key = openssl_pkey_get_private($pem);
        if (!$key)
            return false;

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256))
            return false;

        // Convert DER-encoded ECDSA signature to raw r||s format (64 bytes)
        $rawSig = self::derToRaw($signature);
        if (!$rawSig)
            return false;

        return $signingInput . '.' . self::base64UrlEncode($rawSig);
    }

    /**
     * Encrypt the push payload using the Web Push content encoding.
     */
    private function encryptPayload($payload, $userPublicKey, $userAuth, $encoding) {

        // Generate ephemeral EC key pair
        $ephemeral = @openssl_pkey_new(array(
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        if (!$ephemeral)
            return false;

        $ephDetails = openssl_pkey_get_details($ephemeral);
        if (!$ephDetails || !isset($ephDetails['ec']))
            return false;

        $ephX = str_pad($ephDetails['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $ephY = str_pad($ephDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $serverPublicKey = "\x04" . $ephX . $ephY;

        // Perform ECDH: shared_secret = ECDH(ephemeral_private, user_public_key)
        $userKeyPEM = $this->buildECPublicKeyPEM($userPublicKey);
        if (!$userKeyPEM)
            return false;

        $userKeyRes = openssl_pkey_get_public($userKeyPEM);
        if (!$userKeyRes)
            return false;

        $sharedSecret = openssl_pkey_derive($userKeyRes, $ephemeral);
        if ($sharedSecret === false)
            return false;

        // Generate random salt (16 bytes)
        $salt = random_bytes(16);

        if ($encoding === 'aes128gcm') {
            return $this->encryptAes128gcm($payload, $sharedSecret, $userPublicKey, $userAuth,
                $serverPublicKey, $salt);
        } else {
            return $this->encryptAesgcm($payload, $sharedSecret, $userPublicKey, $userAuth,
                $serverPublicKey, $salt);
        }
    }

    /**
     * aes128gcm content encoding (RFC 8291)
     */
    private function encryptAes128gcm($payload, $sharedSecret, $userPublicKey, $userAuth,
                                       $serverPublicKey, $salt) {
        // IKM info for HKDF extract
        $ikmInfo = "WebPush: info\0" . $userPublicKey . $serverPublicKey;

        // PRK = HKDF-Extract(auth, shared_secret)
        $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);

        // IKM = HKDF-Expand(PRK, ikm_info, 32)
        $ikm = self::hkdfExpand($prk, $ikmInfo, 32);

        // Now derive CEK and nonce from the salt + IKM
        $prk2 = hash_hmac('sha256', $ikm, $salt, true);

        $cekInfo = "Content-Encoding: aes128gcm\0";
        $nonceInfo = "Content-Encoding: nonce\0";

        $cek = self::hkdfExpand($prk2, $cekInfo, 16);
        $nonce = self::hkdfExpand($prk2, $nonceInfo, 12);

        // Pad the payload: payload || 0x02 (delimiter)
        $padded = $payload . "\x02";

        // Encrypt with AES-128-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16  // tag length
        );

        if ($ciphertext === false)
            return false;

        // Build aes128gcm body:
        // salt (16) || record_size (4, big-endian) || key_id_len (1) || key_id (server public key, 65)
        // followed by ciphertext || tag
        $recordSize = strlen($padded) + 16; // ciphertext + tag
        $body = $salt
            . pack('N', $recordSize + 86) // header size (16+4+1+65) + ciphertext size
            . chr(65) // key_id_len = 65 (uncompressed EC point)
            . $serverPublicKey
            . $ciphertext . $tag;

        return array(
            'body' => $body,
        );
    }

    /**
     * aesgcm content encoding (legacy, for older browsers)
     */
    private function encryptAesgcm($payload, $sharedSecret, $userPublicKey, $userAuth,
                                    $serverPublicKey, $salt) {
        // PRK for auth
        $authInfo = "Content-Encoding: auth\0";
        $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);
        $authIkm = self::hkdfExpand($prk, $authInfo, 32);

        // PRK for content keys
        $prk2 = hash_hmac('sha256', $authIkm, $salt, true);

        $context = "\0" // type
            . pack('n', strlen($userPublicKey)) . $userPublicKey
            . pack('n', strlen($serverPublicKey)) . $serverPublicKey;

        $cekInfo = "Content-Encoding: aesgcm\0P-256" . $context;
        $nonceInfo = "Content-Encoding: nonce\0P-256" . $context;

        $cek = self::hkdfExpand($prk2, $cekInfo, 16);
        $nonce = self::hkdfExpand($prk2, $nonceInfo, 12);

        // Pad: 2-byte big-endian padding length (0) + payload
        $padded = "\0\0" . $payload;

        $tag = '';
        $ciphertext = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($ciphertext === false)
            return false;

        return array(
            'body' => $ciphertext . $tag,
            'serverPublicKeyB64' => self::base64UrlEncode($serverPublicKey),
            'saltB64' => self::base64UrlEncode($salt),
        );
    }

    /**
     * Build a PEM-formatted EC private key from raw components.
     */
    private function buildECPrivateKeyPEM($privateKey, $publicKey) {
        // EC PRIVATE KEY ASN.1 structure:
        // SEQUENCE {
        //   INTEGER 1 (version)
        //   OCTET STRING (private key, 32 bytes)
        //   [0] OID prime256v1
        //   [1] BIT STRING (public key, 65 bytes)
        // }

        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID for prime256v1
        $oidTag = "\xa0" . chr(strlen($oid)) . $oid;

        $pubBits = "\x00" . $publicKey; // leading 0 for unused bits
        $pubBitString = "\x03" . self::asn1Length(strlen($pubBits)) . $pubBits;
        $pubTag = "\xa1" . self::asn1Length(strlen($pubBitString)) . $pubBitString;

        $privOctet = "\x04" . chr(strlen($privateKey)) . $privateKey;
        $version = "\x02\x01\x01";

        $inner = $version . $privOctet . $oidTag . $pubTag;
        $seq = "\x30" . self::asn1Length(strlen($inner)) . $inner;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($seq), 64, "\n")
            . "-----END EC PRIVATE KEY-----";
    }

    /**
     * Build a PEM-formatted EC public key from raw uncompressed point.
     */
    private function buildECPublicKeyPEM($publicKey) {
        // SubjectPublicKeyInfo ASN.1 structure for EC P-256:
        // SEQUENCE {
        //   SEQUENCE {
        //     OID ecPublicKey (1.2.840.10045.2.1)
        //     OID prime256v1 (1.2.840.10045.3.1.7)
        //   }
        //   BIT STRING (public key)
        // }

        $ecOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // ecPublicKey OID
        $curveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // prime256v1 OID
        $algSeq = "\x30" . chr(strlen($ecOid) + strlen($curveOid)) . $ecOid . $curveOid;

        $pubBits = "\x00" . $publicKey;
        $bitString = "\x03" . self::asn1Length(strlen($pubBits)) . $pubBits;

        $outer = $algSeq . $bitString;
        $seq = "\x30" . self::asn1Length(strlen($outer)) . $outer;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($seq), 64, "\n")
            . "-----END PUBLIC KEY-----";
    }

    /**
     * Convert DER-encoded ECDSA signature to raw r||s (64 bytes for ES256).
     */
    private static function derToRaw($der) {
        // DER: 0x30 <len> 0x02 <r_len> <r> 0x02 <s_len> <s>
        $pos = 0;
        if (ord($der[$pos]) !== 0x30)
            return false;
        $pos++;
        $pos += self::readAsn1Length($der, $pos, $totalLen);

        // Read r
        if (ord($der[$pos]) !== 0x02)
            return false;
        $pos++;
        $pos += self::readAsn1Length($der, $pos, $rLen);
        $r = substr($der, $pos, $rLen);
        $pos += $rLen;

        // Read s
        if (ord($der[$pos]) !== 0x02)
            return false;
        $pos++;
        $pos += self::readAsn1Length($der, $pos, $sLen);
        $s = substr($der, $pos, $sLen);

        // Trim leading zeros and pad to 32 bytes
        $r = ltrim($r, "\0");
        $s = ltrim($s, "\0");
        $r = str_pad($r, 32, "\0", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\0", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Read ASN.1 length byte(s). Returns number of bytes consumed.
     */
    private static function readAsn1Length($data, $offset, &$length) {
        $byte = ord($data[$offset]);
        if ($byte < 0x80) {
            $length = $byte;
            return 1;
        }
        $numBytes = $byte & 0x7f;
        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }
        return 1 + $numBytes;
    }

    /**
     * Encode ASN.1 length.
     */
    private static function asn1Length($length) {
        if ($length < 0x80)
            return chr($length);
        if ($length < 0x100)
            return "\x81" . chr($length);
        return "\x82" . pack('n', $length);
    }

    /**
     * HKDF-Expand (RFC 5869) with SHA-256.
     */
    private static function hkdfExpand($prk, $info, $length) {
        $t = '';
        $lastBlock = '';
        $blockIndex = 1;
        while (strlen($t) < $length) {
            $lastBlock = hash_hmac('sha256', $lastBlock . $info . chr($blockIndex), $prk, true);
            $t .= $lastBlock;
            $blockIndex++;
        }
        return substr($t, 0, $length);
    }

    // --- Base64url helpers ---

    static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
