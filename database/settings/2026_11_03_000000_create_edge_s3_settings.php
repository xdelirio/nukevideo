<?php

use App\Settings\EdgeS3Settings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The read-only S3 key for the proxies ({@see EdgeS3Settings}). Empty by default: an installation
 * that never sets one keeps signing with the main key, exactly as before.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('edge_s3.access_key_id', '');
        $this->migrator->add('edge_s3.secret_access_key', '');
    }

    public function down(): void
    {
        $this->migrator->delete('edge_s3.access_key_id');
        $this->migrator->delete('edge_s3.secret_access_key');
    }
};
