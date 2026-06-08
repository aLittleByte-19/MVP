<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/tls/generate-local-cert.php <cert-path> <key-path>\n");
    exit(1);
}

[, $certPath, $keyPath] = $argv;

function fail_with_openssl_errors(string $message): never
{
    fwrite(STDERR, $message."\n");

    while ($error = openssl_error_string()) {
        fwrite(STDERR, $error."\n");
    }

    exit(1);
}

foreach ([dirname($certPath), dirname($keyPath)] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Unable to create directory: {$directory}\n");
        exit(1);
    }
}

$opensslConfig = <<<'OPENSSL'
[ req ]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[ req_distinguished_name ]
CN = poc.localhost

[ v3_req ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[ alt_names ]
DNS.1 = localhost
DNS.2 = poc.localhost
DNS.3 = traefik
IP.1 = 127.0.0.1
OPENSSL;

$configPath = tempnam(sys_get_temp_dir(), 'poc-openssl-');

if ($configPath === false || file_put_contents($configPath, $opensslConfig) === false) {
    fwrite(STDERR, "Unable to write temporary OpenSSL configuration.\n");
    exit(1);
}

try {
    $configArgs = [
        'config' => $configPath,
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $privateKey = openssl_pkey_new($configArgs);

    if ($privateKey === false) {
        fail_with_openssl_errors('Unable to generate private key.');
    }

    $csr = openssl_csr_new(['commonName' => 'poc.localhost'], $privateKey, [
        ...$configArgs,
        'req_extensions' => 'v3_req',
    ]);

    if ($csr === false) {
        fail_with_openssl_errors('Unable to generate certificate signing request.');
    }

    $certificate = openssl_csr_sign($csr, null, $privateKey, 365, [
        ...$configArgs,
        'x509_extensions' => 'v3_req',
    ]);

    if ($certificate === false) {
        fail_with_openssl_errors('Unable to sign local certificate.');
    }

    if (! openssl_x509_export($certificate, $certificateOutput)) {
        fail_with_openssl_errors('Unable to export local certificate.');
    }

    if (! openssl_pkey_export($privateKey, $privateKeyOutput, null, $configArgs)) {
        fail_with_openssl_errors('Unable to export private key.');
    }

    if (file_put_contents($certPath, $certificateOutput) === false) {
        fwrite(STDERR, "Unable to write certificate: {$certPath}\n");
        exit(1);
    }

    if (file_put_contents($keyPath, $privateKeyOutput) === false) {
        fwrite(STDERR, "Unable to write private key: {$keyPath}\n");
        exit(1);
    }

    chmod($keyPath, 0600);

    echo "Generated local TLS certificate: {$certPath}\n";
    echo "Generated local TLS key: {$keyPath}\n";
} finally {
    if (is_string($configPath) && file_exists($configPath)) {
        unlink($configPath);
    }
}
