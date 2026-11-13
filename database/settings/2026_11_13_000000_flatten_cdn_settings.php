<?php

use App\Jobs\Concerns\CompletesVideo;
use App\Settings\CdnSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The proxy nodes are the only delivery there is, so their config stops living one level down in
 * a per-provider map ({@see CdnSettings}): `cdn.providers.self_hosted.*` becomes `cdn.*`.
 *
 * The two legacy rows are REWRITTEN here, not deleted: `cdn.provider` is forced to `self_hosted`
 * and `cdn.providers` is cut down to the edge's six values. Nothing in this release reads them,
 * but the previous one does, and worker nodes run a baked image that keeps running it until each
 * node is redeployed. That release resolves its CDN provider from `cdn.provider` every time it
 * builds a webhook payload, which a worker does as it closes a video out ({@see CompletesVideo}):
 * with the rows gone, every video finishing in that window would throw after it was marked
 * completed. Delete both rows in a later release, once no node can still be on the old image.
 *
 * What is dropped now is everything no release reads: the Bunny pull zone's host, token key and
 * API key, and four edge keys (`secure_token_expires`, `secure_token_query_expires`,
 * `cache_max_size`, `cache_inactive`) that have been dead since the edge started sizing its own
 * cache. `down()` cannot bring those back.
 *
 * Every step checks what is there first, so a run interrupted halfway can be run again.
 */
return new class extends SettingsMigration
{
    private const DEFAULTS = [
        'token_secret' => '',
        'token_name' => '__hdnea__',
        'token_window' => 3600,
        'bind_ip' => false,
        'allowed_domains' => [],
        'allow_null_origin' => false,
    ];

    public function up(): void
    {
        $edge = [];

        if ($this->migrator->exists('cdn.providers')) {
            // Read and rewritten in one step. The migrator hands the JSON over decoded as
            // objects, hence the round trip.
            $this->migrator->update('cdn.providers', function ($providers) use (&$edge) {
                $stored = json_decode((string) json_encode($providers), true)['self_hosted'] ?? [];

                foreach (self::DEFAULTS as $name => $default) {
                    $value = $stored[$name] ?? $default;
                    settype($value, gettype($default));
                    $edge[$name] = is_array($value) ? array_values($value) : $value;
                }

                return ['self_hosted' => $edge];
            });
        }

        if ($this->migrator->exists('cdn.provider')) {
            $this->migrator->update('cdn.provider', fn () => 'self_hosted');
        }

        foreach (self::DEFAULTS as $name => $default) {
            if (! $this->migrator->exists("cdn.{$name}")) {
                $this->migrator->add("cdn.{$name}", $edge[$name] ?? $default);
            }
        }
    }

    public function down(): void
    {
        $edge = [];

        foreach (array_keys(self::DEFAULTS) as $name) {
            if ($this->migrator->exists("cdn.{$name}")) {
                $this->migrator->update("cdn.{$name}", function ($value) use (&$edge, $name) {
                    return $edge[$name] = $value;
                });
                $this->migrator->delete("cdn.{$name}");
            }
        }

        // The panel wrote the flat values while this was applied, so they win over the copy the
        // legacy map was left with.
        $this->migrator->deleteIfExists('cdn.providers');
        $this->migrator->add('cdn.providers', ['self_hosted' => $edge + self::DEFAULTS]);

        if (! $this->migrator->exists('cdn.provider')) {
            $this->migrator->add('cdn.provider', 'self_hosted');
        }
    }
};
