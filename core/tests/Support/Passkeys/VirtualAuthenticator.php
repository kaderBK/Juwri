<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support\Passkeys;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * P1-19 test helper: a software WebAuthn authenticator (what Chrome's virtual authenticator does). It
 * owns a real P-256 key, answers navigator.credentials.create() with a "none" attestation and
 * navigator.credentials.get() with an ES256 signature over authenticatorData || SHA-256(clientDataJSON),
 * and returns the browser's JSON form — so the tests run real attestation and assertion vectors through
 * laravel/passkeys and web-auth/webauthn-lib, nothing mocked.
 */
final class VirtualAuthenticator
{
    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_AT = 0x40;

    /** The AAGUID this authenticator reports (any 16 bytes; not in the package's label list). */
    public const AAGUID = '0123456789abcdef';

    public string $credentialId;

    public string $userHandle = '';

    public int $signCount = 0;

    private OpenSSLAsymmetricKey $key;

    public function __construct(
        public string $rpId = 'localhost',
        public string $origin = 'https://localhost',
        ?string $credentialId = null,
    ) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($key instanceof OpenSSLAsymmetricKey);
        $this->key = $key;
        $this->credentialId = $credentialId ?? random_bytes(32);
    }

    /**
     * Registration response for the server's creation options (the `options` JSON object).
     *
     * @param  array<string, mixed>  $options
     * @param  array{rpId?: string, origin?: string, type?: string, flags?: int}  $override
     * @return array<string, mixed>
     */
    public function create(array $options, array $override = []): array
    {
        $this->userHandle = Base64UrlSafe::decodeNoPadding((string) $options['user']['id']);
        $clientData = $this->clientData($override['type'] ?? 'webauthn.create', (string) $options['challenge'], $override['origin'] ?? $this->origin);

        $authData = hash('sha256', $override['rpId'] ?? $this->rpId, true)
            .chr($override['flags'] ?? (self::FLAG_UP | self::FLAG_UV | self::FLAG_AT))
            .pack('N', $this->signCount)
            .self::AAGUID
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => [],
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64((string) $attestation),
                'transports' => ['internal', 'hybrid'],
            ],
        ];
    }

    /**
     * Sign-in (assertion) response for the server's request options.
     *
     * @param  array<string, mixed>  $options
     * @param  array{rpId?: string, origin?: string, signCount?: int, flags?: int, challenge?: string}  $override
     * @return array<string, mixed>
     */
    public function get(array $options, array $override = []): array
    {
        $this->signCount = $override['signCount'] ?? $this->signCount + 1;
        $clientData = $this->clientData('webauthn.get', $override['challenge'] ?? (string) $options['challenge'], $override['origin'] ?? $this->origin);

        $authData = hash('sha256', $override['rpId'] ?? $this->rpId, true)
            .chr($override['flags'] ?? (self::FLAG_UP | self::FLAG_UV))
            .pack('N', $this->signCount);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => [],
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64((string) $signature),
                'userHandle' => self::b64($this->userHandle),
            ],
        ];
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return (string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** COSE_Key of the public key: EC2, ES256, P-256. */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));
    }

    private static function b64(string $bytes): string
    {
        return Base64UrlSafe::encodeUnpadded($bytes);
    }
}
