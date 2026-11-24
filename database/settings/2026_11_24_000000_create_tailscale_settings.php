<?php

use App\Settings\TailscaleSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The Tailscale auth key the workers join the tailnet with ({@see TailscaleSettings}). Empty by
 * default: an installation that never sets one installs Tailscale on its workers and leaves them
 * out of the tailnet, as it always did.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->addEncrypted('tailscale.auth_key', '');
    }

    public function down(): void
    {
        $this->migrator->delete('tailscale.auth_key');
    }
};
