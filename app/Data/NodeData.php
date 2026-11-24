<?php

namespace App\Data;

use App\Enums\NodeAccel;
use App\Enums\NodeType;
use App\Models\Node;
use Spatie\LaravelData\Data;

class NodeData extends Data
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        public ?string $user,
        public string $ipAddress,
        public NodeType $type,
        public ?NodeAccel $accel,
        public ?string $hostname,
        public ?bool $cloudflareProxied,
        /** The address on the tailnet, or null when the node has not joined it. */
        public ?string $tailscaleIp,
        public bool $isActive,
        public bool $isDraining,
        public bool $isHealthy,
        public int $healthFailures,
        public ?string $lastHealthyAt,
        /** @var ServiceStatusData[] */
        public array $services,
        public ?string $log,
        public ?string $env,
        public ?string $lastSeenAt,
    ) {}

    public static function fromModel(Node $node): self
    {
        return new self(
            id: $node->id,
            uuid: $node->uuid,
            name: $node->name,
            user: $node->user,
            ipAddress: $node->ip_address,
            type: $node->type,
            accel: $node->accel,
            hostname: $node->hostname,
            cloudflareProxied: $node->cloudflare_proxied,
            tailscaleIp: $node->tailscale_ip,
            isActive: $node->is_active,
            isDraining: (bool) $node->is_draining,
            isHealthy: $node->isHealthy(),
            healthFailures: (int) $node->health_failures,
            lastHealthyAt: $node->last_healthy_at?->toIso8601String(),
            services: ServiceStatusData::collect($node->services ?? []),
            log: $node->log,
            env: $node->env,
            lastSeenAt: $node->updated_at?->diffForHumans(),
        );
    }
}
