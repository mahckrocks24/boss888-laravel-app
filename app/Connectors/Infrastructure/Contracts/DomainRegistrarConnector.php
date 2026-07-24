<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Registrar capability contract.
 *
 * Directive §15: a domain REGISTERED through LevelUp Growth and a domain merely
 * CONNECTED to LevelUp Growth are different concepts. This contract covers only
 * the former — registrar authority. Connecting an externally-registered domain is
 * CustomHostnameConnector's job and deliberately does not live here, so the code
 * cannot imply we can renew or transfer a domain we do not manage.
 */
interface DomainRegistrarConnector extends InfrastructureConnector
{
    /**
     * @return ProviderResult data: ['available'=>bool,'domain'=>string,'premium'=>bool]
     */
    public function searchDomain(string $domain): ProviderResult;

    /**
     * Price quote in MINOR UNITS with explicit currency — never a float.
     * @return ProviderResult data: ['amount_minor'=>int,'currency'=>string,'years'=>int]
     */
    public function quoteRegistration(string $domain, int $years): ProviderResult;

    public function registerDomain(string $domain, int $years, array $contacts, string $idempotencyKey): ProviderResult;

    public function transferDomain(string $domain, string $authCode, array $contacts, string $idempotencyKey): ProviderResult;

    public function renewDomain(string $domain, int $years, string $idempotencyKey): ProviderResult;

    public function getDomainStatus(string $domain): ProviderResult;

    public function updateNameservers(string $domain, array $nameservers, string $idempotencyKey): ProviderResult;
}
