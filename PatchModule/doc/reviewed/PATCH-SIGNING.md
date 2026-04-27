# Patch Metadata Signing

## Overview

The patch server can optionally sign each patch entry returned by the `/patches/check` endpoint. The PatchModule verifies these signatures automatically, excluding any patch whose signature does not match. This prevents a man-in-the-middle or a compromised cache from substituting a malicious archive.

Signing is an optional, gradual-rollout feature: patches without signature data are accepted silently, so existing deployments continue working before the server is configured with a key pair.

## How It Works

### Server Side

When `PATCH_SIGNING_PRIVATE_KEY_PATH` is configured, the server signs a canonical JSON payload for each patch before returning it:

```
payload = {
    patch_id:   <int>    database ID of the patch
    sha256:     <string> SHA-256 hex digest of the patch file
    version:    <string> semantic version, e.g. "1.2.3"
    package_id: <int>    database ID of the associated package
    exp:        <int>    Unix timestamp 24 hours from now (signature expiry)
}
```

The signature is `openssl_sign(json_encode(payload), key, OPENSSL_ALGO_SHA256)`, base64url-encoded (`+/` → `-_`, padding stripped).

The response includes `signature` and `public_key` (PEM) alongside each patch entry.

### PatchModule Client Side

On receipt of `/patches/check`, `PatchChecker` runs a three-level check for each patch:

1. **Key pinning** — if `expected_public_key_pem` is configured, the returned `public_key` is compared using OpenSSL key details (normalises whitespace). Mismatched key → patch rejected (WARNING log).
2. **Full cryptographic verification** — if `signature`, `public_key`, `exp`, and `package_id` are all present, `SignatureVerifierInterface::verify()` is called. Invalid signature → patch rejected (WARNING log).
3. **Partial data / no signing** — if any required field is absent, verification is skipped and a DEBUG message is logged. The patch is still accepted.

## Current Server Limitation

As of LicenseManager v2.8.0, the server returns `signature` and `public_key` per patch but does **not** yet include `exp` or `package_id` in the patch entry. This means step 2 (full cryptographic verification) is inactive until those fields are added.

**Key pinning (step 1) is the primary defence in the current configuration.** Set `expected_public_key_pem` in the module config to enable it.

Full cryptographic verification activates automatically once the server adds `exp` and `package_id` to patch entries — no module changes needed.

## Configuration

| Key                     | Type                        | Required | Default      | Description                                      |
|-------------------------|-----------------------------|----------|--------------|--------------------------------------------------|
| `expected_public_key_pem` | `string`                  | No       | `null`       | PEM of the trusted server public key (pinning)   |
| `signature_verifier`    | `SignatureVerifierInterface` | No      | OpenSSL impl | Custom verifier replacing the default            |

```php
$module = new PatchModule([
    // ... other config ...
    'expected_public_key_pem' => file_get_contents('/etc/app/patch-server-public.pem'),
]);
```

## Custom Verifier

Implement `PatchModule\Contracts\SignatureVerifierInterface` and pass it as `signature_verifier` in the config to replace the OpenSSL default:

```php
interface SignatureVerifierInterface {
    public function verify(array $payload, string $publicKeyPem, string $signatureB64Url): bool;
}
```

## What Is Signed, What Is Not

The signature covers: `patch_id`, `sha256`, `version`, `package_id`, `exp`. It does **not** cover `release_notes` or `file_size`. These fields are informational; the SHA-256 hash of the file itself is what guarantees archive integrity at download time.

## Server Version Requirement

Signing requires LicenseManager **v2.8.0 or later**. Earlier servers do not return `signature` or `public_key`; the module handles this gracefully (step 3 above).
