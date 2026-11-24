<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address a worker has on the tailnet, read back from the node at its deploy. Null until the
 * node has joined, which is every node that predates the Tailscale setting and every worker
 * deployed without an auth key. Nothing routes through it yet: it is recorded so the panel can
 * show where each worker is reachable from the private network.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nodes', 'tailscale_ip')) {
            return;
        }

        Schema::table('nodes', function (Blueprint $table) {
            $table->string('tailscale_ip', 45)->nullable()->after('cloudflare_proxied');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('nodes', 'tailscale_ip')) {
            Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('tailscale_ip'));
        }
    }
};
