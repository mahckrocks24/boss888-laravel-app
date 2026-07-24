<?php

namespace App\Services\Domains;

/**
 * Provider-neutral snapshot of a custom hostname. Every provider adapter maps
 * its own API payload onto this shape so the application service never touches
 * Cloudflare-specific fields.
 */
class CustomHostnameResult
{
    /**
     * @param string      $providerHostnameId Provider's opaque hostname id ('' if none yet)
     * @param string      $hostname           Normalized hostname
     * @param string|null $ownershipStatus    Normalized: pending|active|blocked|moved|deleted|null
     * @param string|null $sslStatus          Normalized: pending_validation|pending_issuance|pending_deployment|active|null
     * @param string|null $validationMethod   'txt' | 'http' | null
     * @param array       $validationRecords  List of ['type'=>'CNAME|TXT','name'=>..,'value'=>..,'purpose'=>'routing|ownership|ssl']
     * @param bool        $active             Provider considers hostname fully live (ownership + SSL)
     * @param array       $errors             Human-readable provider error strings
     * @param array       $raw                Raw provider payload snapshot (for metadata/debug)
     */
    public function __construct(
        public string $providerHostnameId = '',
        public string $hostname = '',
        public ?string $ownershipStatus = null,
        public ?string $sslStatus = null,
        public ?string $validationMethod = null,
        public array $validationRecords = [],
        public bool $active = false,
        public array $errors = [],
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'provider_hostname_id' => $this->providerHostnameId,
            'hostname'             => $this->hostname,
            'ownership_status'     => $this->ownershipStatus,
            'ssl_status'           => $this->sslStatus,
            'validation_method'    => $this->validationMethod,
            'validation_records'   => $this->validationRecords,
            'active'               => $this->active,
            'errors'               => $this->errors,
        ];
    }
}
