<?php

namespace App\Models;

use App\Models\Concerns\ReadsEncodingQuery;
use App\Services\Concerns\BuildsArguments;
use App\Services\OnVideoUploadedService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Template extends Model implements Sortable
{
    use BuildsArguments, ReadsEncodingQuery, SortableTrait;

    /** Marker for "encodes on the CPU queue", the counterpart of a node's GPU accel. */
    public const CPU = 'cpu';

    protected $fillable = [
        'name',
        'query',
        'enabled',
        'keep_processed_files',
        'keep_original',
        'user_id',
        'project_id',
    ];

    // The column default alone leaves a just-created model with `enabled` unset in memory, and the
    // store/duplicate responses are built from that instance — they reported a template as disabled
    // the moment it was created.
    protected $attributes = [
        'enabled' => true,
    ];

    protected function casts()
    {
        return [
            'query' => 'json',
            'enabled' => 'boolean',
            'keep_processed_files' => 'boolean',
            'keep_original' => 'boolean',
        ];
    }

    /** Hand ordering, per project: the only scope a template is ever listed in. */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('project_id', $this->project_id);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });

        // Every way a template comes to exist or changes its encoding — the endpoints, a preset,
        // a duplicate, a seeder — goes through here, so none of them can skip a version.
        static::saved(function (Template $template) {
            // `wasRecentlyCreated` stays true for the instance's whole life, so on its own it would
            // cut a version on every later save of the model that created it.
            if (($template->wasRecentlyCreated && ! $template->current_version_id) || $template->wasChanged('query')) {
                $template->cutVersion();
            }
        });
    }

    /**
     * Record the current `query` as the next immutable version and make it current. The pointer is
     * written without events: it is bookkeeping of this very save, not an edit of its own.
     */
    private function cutVersion(): void
    {
        DB::transaction(function () {
            $version = $this->versions()->create([
                'version' => (int) $this->versions()->lockForUpdate()->max('version') + 1,
                'query' => $this->query,
            ]);

            $this->forceFill(['current_version_id' => $version->id])->saveQuietly();
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    /** The version an upload made now is pinned to ({@see OnVideoUploadedService}). */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function scopeFindOrFailByUlid(Builder $query, $ulid)
    {
        return $query->where('ulid', $ulid)->firstOrFail();
    }

    /** Preview ffmpeg commands for each output variant of this template. */
    public function buildCommands(): array
    {
        $commands = [];

        foreach ($this->query['outputs'] ?? [] as $output) {
            $codec = $output['video_codec'] ?? null;

            foreach ($output['variants'] ?? [] as $variant) {
                $args = [];

                if ($codec) {
                    $args[] = "-c:v {$codec}";
                }

                $width = $variant['width'] ?? 0;
                $height = $variant['height'] ?? 0;
                if ($width > 0 && $height > 0) {
                    $args[] = "-vf scale={$width}:{$height}";
                }

                $args = array_merge($args, $this->buildParamsArguments($variant, 'video'));

                $audio = $output['audio'] ?? [];
                $audioCodec = $audio['audio_codec'] ?? null;
                if ($audioCodec) {
                    $args[] = "-c:a {$audioCodec}";
                }

                $channel = $audio['channels'][0] ?? [];
                $args = array_merge($args, $this->buildParamsArguments($channel, 'audio'));

                $argString = implode(' ', $args);
                $commands[] = "ffmpeg -hide_banner -y -i \"input\" {$argString} \"output\"";
            }
        }

        return $commands;
    }
}
