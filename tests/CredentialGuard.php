<?php

declare(strict_types=1);

/**
 * This file is part of the PohodaRaiffeisenbank package
 *
 * https://github.com/Spoje-NET/pohoda-raiffeisenbank
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pohoda\RaiffeisenBank;

/**
 * Skip tests that need a real RB API certificate or a reachable mServer instead of
 * failing/erroring when this environment doesn't have working credentials.
 */
trait CredentialGuard
{
    /**
     * Is CERT_FILE present, readable, PKCS12-decodable with CERT_PASS, and not expired?
     */
    protected function certificateUsable(): bool
    {
        $certFile = \Ease\Shared::cfg('CERT_FILE');
        $certPass = \Ease\Shared::cfg('CERT_PASS');

        if (!$certFile || !PohodaBankClient::checkCertificatePresence($certFile)) {
            return false;
        }

        $certs = [];

        if (openssl_pkcs12_read((string) file_get_contents($certFile), $certs, (string) $certPass) === false) {
            return false;
        }

        $parsed = openssl_x509_parse($certs['cert']);

        return $parsed && isset($parsed['validTo_time_t']) && $parsed['validTo_time_t'] > time();
    }

    protected function skipUnlessCertificateUsable(): void
    {
        if (!$this->certificateUsable()) {
            $this->markTestSkipped('RB test certificate (CERT_FILE) is missing, unreadable, or expired in this environment');
        }
    }

    /**
     * Is the configured mServer (POHODA_URL) reachable?
     */
    protected function mServerReachable(): bool
    {
        $host = parse_url((string) \Ease\Shared::cfg('POHODA_URL'), \PHP_URL_HOST);
        $port = parse_url((string) \Ease\Shared::cfg('POHODA_URL'), \PHP_URL_PORT) ?: 80;

        if (!$host) {
            return false;
        }

        $connection = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    protected function skipUnlessMServerReachable(): void
    {
        if (!$this->mServerReachable()) {
            $this->markTestSkipped('mServer (POHODA_URL) is not reachable in this environment');
        }
    }
}
