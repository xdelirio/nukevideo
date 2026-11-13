<?php

use App\Settings\CdnSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The proxy nodes are the only delivery there is, so their config stops living one level down in
 * a per-provider map ({@see CdnSettings}): `cdn.providers.self_hosted.*` becomes `cdn.*`, and the
 * `cdn.provider` switch that chose between it and Bunny goes.
 *
 * What is dropped with the map is everything nothing reads any more: the Bunny pull zone's host,
 * token key and API key, and four edge keys (`secure_token_expires`, `secure_token_query_expires`,
 * `cache_max_size`, `cache_inactive`) that have been dead since the edge started sizing its own
 * cache. `down()` rebuilds the map from the flat values, but cannot bring those back.
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
            // The migrator has no getter; an update that changes nothing is how a value is read
            // through whichever repository the settings live in. It hands the JSON over decoded
            // as objects, hence the round trip.
            $this->migrator->update('cdn.providers', function ($providers) use (&$edge) {
                $edge = json_decode((string) json_encode($providers), true)['self_hosted'] ?? [];

                return $providers;
            });
        }

        foreach (self::DEFAULTS as $name => $default) {
            if ($this->migrator->exists("cdn.{$name}")) {
                continue;
            }

            $value = $edge[$name] ?? $default;
            settype($value, gettype($default));

            $this->migrator->add("cdn.{$name}", is_array($value) ? array_values($value) : $value);
        }

        $this->migrator->deleteIfExists('cdn.providers');
        $this->migrator->deleteIfExists('cdn.provider');
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

        if (! $this->migrator->exists('cdn.providers')) {
            $this->migrator->add('cdn.providers', ['self_hosted' => $edge + self::DEFAULTS]);
        }

        if (! $this->migrator->exists('cdn.provider')) {
            $this->migrator->add('cdn.provider', 'self_hosted');
        }
    }
};
