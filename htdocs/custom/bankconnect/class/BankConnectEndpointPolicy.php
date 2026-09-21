<?php
/**
 * Fail-closed endpoint policy for BankConnect and external service endpoints.
 */
class BankConnectEndpointPolicy
{
    private const BANKCONNECT_ENDPOINTS = [
        'test' => 'stest.bankconnect.dk',
        'production' => 'www.bankconnectservices.dk',
    ];

    public static function validateBankConnect(string $endpoint, string $environment = 'production'): string
    {
        $environment = strtolower(trim($environment));
        if (!isset(self::BANKCONNECT_ENDPOINTS[$environment])) {
            throw new BankConnectException('Unsupported BankConnect environment: '.$environment);
        }

        return self::validateHttpsHost($endpoint, self::BANKCONNECT_ENDPOINTS[$environment]);
    }

    public static function validateHttpsHost(string $endpoint, string $expectedHost): string
    {
        $parts = parse_url(trim($endpoint));
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            throw new BankConnectException('BankConnect endpoint must use HTTPS');
        }
        if (!isset($parts['host']) || strcasecmp($parts['host'], $expectedHost) !== 0) {
            throw new BankConnectException('BankConnect endpoint host is not allowed');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new BankConnectException('BankConnect endpoint contains unsupported URL credentials/query/fragment');
        }

        $path = $parts['path'] ?? '';
        if ($path !== '/2019/04/04/services/CorporateService') {
            throw new BankConnectException('BankConnect endpoint path is not the v3.7 CorporateService endpoint');
        }

        return 'https://'.$expectedHost.$path;
    }

    public static function validateMistral(string $endpoint): string
    {
        $parts = parse_url(trim($endpoint));
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            throw new BankConnectException('Mistral endpoint must use HTTPS');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($host, ['api.mistral.ai'], true)) {
            throw new BankConnectException('Mistral endpoint host is not allowed');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new BankConnectException('Mistral endpoint contains unsupported URL credentials/query/fragment');
        }
        return rtrim(trim($endpoint), '/');
    }
}
