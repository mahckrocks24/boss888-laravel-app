<?php

namespace App\Services;

use App\Core\Tenancy\WorkspaceContext;
use App\Models\CustomDomain;
use App\Services\Domains\Contracts\CustomHostnameProvider;
use App\Services\Domains\CustomHostnameResult;
use App\Services\Domains\ProviderException;
use App\Services\Domains\Providers\CloudflareSaasProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * INFRA888 Domains — connect an external customer domain to a website via the
 * Cloudflare-for-SaaS custom-hostname lifecycle (Sprint 3, locked architecture).
 *
 * This is the provider-neutral application service. It NEVER touches Cloudflare
 * fields directly — all provider I/O goes through {@see CustomHostnameProvider}.
 * The lifecycle state lives in the custom_domains table; the legacy
 * websites.custom_domain / domain_verified columns are kept in sync for existing
 * reads.
 *
 * PRODUCTION MUTATION GATE: config('cloudflare.saas_enabled'). While false, no
 * live Cloudflare mutation is performed — connect()/verify()/disconnect() that
 * would mutate the provider short-circuit with a customer-safe "not yet
 * available" result. Method signatures are preserved for the existing routes.
 */
class CustomDomainService
{
    public function __construct(private ?CustomHostnameProvider $provider = null)
    {
        $this->provider = $provider ?: new CloudflareSaasProvider();
    }

    private function gateOpen(): bool
    {
        return (bool) config('cloudflare.saas_enabled', false);
    }

    /** The lifecycle table only exists after the (release-gated) migration. */
    private function tableReady(): bool
    {
        return Schema::hasTable('custom_domains');
    }

    /* =============================================================== connect */

    public function connect(int $websiteId, string $customDomain): array
    {
        $corr = (string) Str::uuid();
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website) {
            return $this->err('Website not found.');
        }
        $workspaceId = (int) $website->workspace_id;

        $hostname = $this->normalize($customDomain);
        if (!$this->validHostname($hostname)) {
            return $this->err('Enter a valid domain such as www.yourbrand.com (no https:// or paths).');
        }
        if ($this->isApex($hostname)) {
            // First release supports subdomains only — apex needs Cloudflare apex
            // proxying (Enterprise). Guide the customer to a subdomain.
            return $this->err(
                'Apex domains (like yourbrand.com) aren’t supported yet — connect a subdomain such as www.yourbrand.com instead.',
                ['apex_unsupported' => true]
            );
        }

        // Production mutation gate FIRST — the gated path performs NO provider
        // mutation and touches NO custom_domains rows, so it is safe to run even
        // before the (release-gated) migration has been applied on production.
        if (!$this->gateOpen() || !$this->tableReady()) {
            $this->log('connect.gated', $corr, $workspaceId, $websiteId, $hostname);
            return [
                'success'  => false,
                'gated'    => true,
                'hostname' => $hostname,
                'preview'  => [[
                    'type' => 'CNAME', 'name' => $hostname,
                    'value' => (string) config('cloudflare.routing_target'), 'purpose' => 'routing',
                ]],
                'error'    => 'Custom domain connection is being finalized and isn’t available yet. You’ll be able to connect this domain shortly.',
            ];
        }

        return WorkspaceContext::run($workspaceId, function () use ($websiteId, $workspaceId, $hostname, $customDomain, $corr) {
            $existing = CustomDomain::where('website_id', $websiteId)
                ->whereIn('state', CustomDomain::LIVE_STATES)
                ->orderByDesc('id')->first();

            // Idempotency: same hostname already in flight/active → return its state.
            if ($existing && $existing->hostname === $hostname) {
                $this->log('connect.idempotent', $corr, $workspaceId, $websiteId, $hostname);
                return $this->state($existing);
            }
            // A different domain is already connected — require disconnect first.
            if ($existing && $existing->hostname !== $hostname) {
                return $this->err('Another domain is already connected to this website. Disconnect it first.');
            }

            // Reuse a prior non-live row for this website, else create one.
            $row = CustomDomain::firstOrNew(['website_id' => $websiteId, 'hostname' => $hostname]);
            $row->workspace_id = $workspaceId;
            $row->domain       = $customDomain;
            $row->is_apex      = false;
            $row->provider     = $this->provider->key();
            $row->state        = CustomDomain::STATE_PENDING_SETUP;
            $row->last_error   = null;
            $row->connected_at = $row->connected_at ?: now();
            $row->save();

            // Create the provider hostname.
            try {
                $result = $this->provider->createCustomHostname($hostname);
            } catch (ProviderException $e) {
                $row->state = CustomDomain::STATE_FAILED;
                $row->last_error = implode('; ', $e->providerErrors() ?: [$e->getMessage()]);
                $row->save();
                $this->log('connect.provider_error', $corr, $workspaceId, $websiteId, $hostname, ['error' => $row->last_error]);
                return $this->err('We couldn’t start the domain connection. '.$row->last_error);
            }

            // Persist the provider id IMMEDIATELY so we can never orphan it.
            $row->provider_hostname_id = $result->providerHostnameId;

            try {
                $this->applyResult($row, $result);
                $row->save();
            } catch (\Throwable $e) {
                // Compensating cleanup: the provider hostname exists but we failed
                // to persist locally — delete it so nothing is orphaned.
                $this->log('connect.persist_failed_compensating', $corr, $workspaceId, $websiteId, $hostname, ['error' => $e->getMessage()]);
                try {
                    $this->provider->deleteCustomHostname($result->providerHostnameId);
                } catch (\Throwable $ce) {
                    // Record for reconciliation to retry.
                    $row->state = CustomDomain::STATE_FAILED;
                    $row->last_error = 'orphaned_provider_hostname:'.$result->providerHostnameId;
                    $row->save();
                    $this->log('connect.compensation_failed', $corr, $workspaceId, $websiteId, $hostname, ['error' => $ce->getMessage()]);
                }
                return $this->err('We hit a problem setting up your domain. Please try again in a moment.');
            }

            $this->syncLegacyColumns($websiteId, $row);
            $this->log('connect.created', $corr, $workspaceId, $websiteId, $hostname, ['state' => $row->state, 'provider_id' => $result->providerHostnameId]);
            return $this->state($row);
        });
    }

    /* ================================================================ verify */

    /** "Check again": re-trigger validation + sync provider status into state. */
    public function verify(int $websiteId): array
    {
        $corr = (string) Str::uuid();
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website) {
            return $this->err('Website not found.');
        }
        $workspaceId = (int) $website->workspace_id;

        if (!$this->tableReady()) {
            return $this->err('No custom domain is connected to this website.');
        }

        return WorkspaceContext::run($workspaceId, function () use ($websiteId, $workspaceId, $corr) {
            $row = CustomDomain::where('website_id', $websiteId)
                ->whereIn('state', CustomDomain::LIVE_STATES)
                ->orderByDesc('id')->first();

            if (!$row) {
                return $this->err('No custom domain is connected to this website.');
            }
            if (!$row->provider_hostname_id || !$this->gateOpen()) {
                // Nothing to sync yet (gated or never created).
                return $this->state($row);
            }

            try {
                $result = $this->provider->verifyCustomHostname($row->provider_hostname_id);
                $this->applyResult($row, $result);
                $row->last_checked_at = now();
                $row->save();
                $this->syncLegacyColumns($websiteId, $row);
                $this->log('verify.synced', $corr, $workspaceId, $websiteId, $row->hostname, ['state' => $row->state]);
            } catch (ProviderException $e) {
                $row->last_checked_at = now();
                $row->last_error = implode('; ', $e->providerErrors() ?: [$e->getMessage()]);
                $row->save();
                $this->log('verify.provider_error', $corr, $workspaceId, $websiteId, $row->hostname, ['error' => $row->last_error]);
            }

            return $this->state($row);
        });
    }

    /* ================================================================ status */

    /** Read-only current lifecycle state (no provider call). */
    public function status(int $websiteId): array
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website) {
            return $this->err('Website not found.');
        }
        $workspaceId = (int) $website->workspace_id;

        if (!$this->tableReady()) {
            return ['success' => true, 'connected' => false, 'gate_open' => $this->gateOpen()];
        }

        return WorkspaceContext::run($workspaceId, function () use ($websiteId) {
            $row = CustomDomain::where('website_id', $websiteId)
                ->whereIn('state', CustomDomain::LIVE_STATES)
                ->orderByDesc('id')->first();
            if (!$row) {
                return ['success' => true, 'connected' => false, 'gate_open' => $this->gateOpen()];
            }
            return $this->state($row);
        });
    }

    /* ============================================================ disconnect */

    public function disconnect(int $websiteId): array
    {
        $corr = (string) Str::uuid();
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website) {
            return $this->err('Website not found.');
        }
        $workspaceId = (int) $website->workspace_id;

        // Idempotent + pre-migration safe: with no lifecycle table there is
        // nothing to disconnect; just ensure legacy columns are clear.
        if (!$this->tableReady()) {
            $this->clearLegacyColumns($websiteId);
            return ['success' => true, 'connected' => false];
        }

        return WorkspaceContext::run($workspaceId, function () use ($websiteId, $workspaceId, $corr) {
            $row = CustomDomain::where('website_id', $websiteId)
                ->whereIn('state', CustomDomain::LIVE_STATES)
                ->orderByDesc('id')->first();

            // Idempotent: nothing connected → clear legacy columns and succeed.
            if (!$row) {
                $this->clearLegacyColumns($websiteId);
                return ['success' => true, 'connected' => false];
            }

            $row->state = CustomDomain::STATE_DISCONNECTING;
            $row->save();

            if ($row->provider_hostname_id && $this->gateOpen()) {
                try {
                    // Idempotent at the adapter: already-deleted counts as success.
                    $this->provider->deleteCustomHostname($row->provider_hostname_id);
                } catch (ProviderException $e) {
                    $row->state = CustomDomain::STATE_FAILED;
                    $row->last_error = 'disconnect_failed:'.implode('; ', $e->providerErrors() ?: [$e->getMessage()]);
                    $row->save();
                    $this->log('disconnect.provider_error', $corr, $workspaceId, $websiteId, $row->hostname, ['error' => $row->last_error]);
                    return $this->err('We couldn’t fully disconnect the domain. Please try again.');
                }
            }

            $row->state = CustomDomain::STATE_DISCONNECTED;
            $row->disconnected_at = now();
            $row->save();
            $this->clearLegacyColumns($websiteId);
            $this->log('disconnect.done', $corr, $workspaceId, $websiteId, $row->hostname);

            return ['success' => true, 'connected' => false, 'hostname' => $row->hostname];
        });
    }

    /* =============================================================== helpers */

    /** Map a provider result onto the row's lifecycle state + records. */
    private function applyResult(CustomDomain $row, CustomHostnameResult $result): void
    {
        $row->ownership_status    = $result->ownershipStatus;
        $row->ssl_status          = $result->sslStatus;
        $row->verification_method = $result->validationMethod;
        $row->verification_records = $this->provider->getValidationInstructions($result);
        $row->metadata            = $result->raw;
        $row->routing_status      = $result->active ? 'active' : 'pending';
        $row->last_error          = $result->errors ? implode('; ', $result->errors) : null;
        $row->state               = $this->deriveState($result);

        if ($row->state === CustomDomain::STATE_ACTIVE) {
            $row->verified_at   = $row->verified_at ?: now();
            $row->ssl_issued_at = $row->ssl_issued_at ?: now();
        }
    }

    private function deriveState(CustomHostnameResult $r): string
    {
        if ($r->active) {
            return CustomDomain::STATE_ACTIVE;
        }
        if (in_array($r->ownershipStatus, ['blocked', 'moved', 'deleted'], true)) {
            return CustomDomain::STATE_FAILED;
        }
        return match ($r->sslStatus) {
            'active'                                                   => CustomDomain::STATE_SSL_PENDING, // ssl ok, routing not yet active
            'pending_issuance', 'pending_deployment', 'initializing'   => CustomDomain::STATE_SSL_PENDING,
            'pending_validation'                                       => CustomDomain::STATE_AWAITING_DNS,
            default                                                    => CustomDomain::STATE_VALIDATING,
        };
    }

    /** Customer-safe status payload for the frontend. */
    private function state(CustomDomain $row): array
    {
        return [
            'success'    => true,
            'connected'  => true,
            'hostname'   => $row->hostname,
            'state'      => $row->state,
            'status'     => $row->customerStatusLabel(),
            'active'     => $row->isActive(),
            'ownership'  => $row->ownership_status,
            'ssl'        => $row->ssl_status,
            'routing'    => $row->routing_status,
            'records'    => $row->verification_records ?? [],
            'last_error' => $row->last_error,
            'checked_at' => optional($row->last_checked_at)->toIso8601String(),
        ];
    }

    private function syncLegacyColumns(int $websiteId, CustomDomain $row): void
    {
        DB::table('websites')->where('id', $websiteId)->update([
            'custom_domain'   => $row->hostname,
            'domain_verified' => $row->isActive() ? 1 : 0,
            'updated_at'      => now(),
        ]);
    }

    private function clearLegacyColumns(int $websiteId): void
    {
        DB::table('websites')->where('id', $websiteId)->update([
            'custom_domain'   => null,
            'domain_verified' => 0,
            'updated_at'      => now(),
        ]);
    }

    private function normalize(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d);   // strip scheme
        $d = preg_replace('#[/:].*$#', '', $d);       // strip path/port
        return rtrim($d, '.');                        // strip trailing dot
    }

    private function validHostname(string $h): bool
    {
        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
            $h
        );
    }

    /** Heuristic apex check (two labels). Multi-part TLDs (co.uk) are refined later. */
    private function isApex(string $h): bool
    {
        return substr_count($h, '.') <= 1;
    }

    private function err(string $message, array $extra = []): array
    {
        return array_merge(['success' => false, 'error' => $message], $extra);
    }

    private function log(string $event, string $corr, int $ws, int $website, string $hostname, array $extra = []): void
    {
        Log::info('domains.'.$event, array_merge([
            'correlation_id' => $corr,
            'workspace_id'   => $ws,
            'website_id'     => $website,
            'hostname'       => $hostname,
            'provider'       => $this->provider->key(),
        ], $extra));
    }
}
