<?php

namespace App\Models;

use App\Enums\StreamStatus;
use App\Observers\StreamObserver;
use App\Services\ChunkTranscodeService;
use App\Services\PackagerCommandBuilder;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[ObservedBy(StreamObserver::class)]
class Stream extends Model
{
    protected $fillable = [
        'video_id',
        'name',
        'path',
        'type',
        'status',
        'package_size',
        'file_size',
        'meta',

        'input_params',

        'width',
        'height',
        'language',
        'forced',
        'channels',

        'error_log',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    protected function casts()
    {
        return [
            'meta' => 'array',
            'input_params' => 'array',
            'package_size' => 'integer',
            'file_size' => 'integer',
            'forced' => 'boolean',
            'status' => StreamStatus::class,
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /** The queue this stream's chunk jobs encode on: CPU by default, per-hardware for GPU codecs. */
    public function encodeQueue(): string
    {
        $accel = ChunkTranscodeService::accelForCodec(data_get($this->input_params, 'video_codec'));

        return $accel ? "video-processing-{$accel}" : 'video-processing';
    }

    /** This stream's `path` with the leading `{videoUlid}/` segment stripped, e.g. `video/{ulid}.mp4`.
     *  String-derived (no `video` relation load) since the prefix is already encoded in `path`. */
    public function relativePath(): string
    {
        return preg_replace('#^[^/]+/#', '', $this->path, 1);
    }

    /** Internal-mirror staging key for this rendition: `{videoUlid}/final/{type}/{ulid}.{ext}`. */
    public function stagingPath(): string
    {
        return strstr($this->path, '/', true).'/'.Video::FINAL_DIR.'/'.$this->relativePath();
    }

    /** This stream's packaged CMAF segment directory on primary S3, inside the video's playback
     *  zone. Matches the layout the packager writes into ({@see PackagerCommandBuilder}). */
    public function segmentsPath(Video $video): string
    {
        return "{$video->playPrefix()}/{$this->ulid}";
    }

    /**
     * Where this stream's raw, downloadable file lives on primary S3. The filename comes from
     * `path` via {@see relativePath} — NOT from `$this->ulid`, which names the segment directory and
     * is a different ULID entirely.
     *
     * Single source of truth for every reader of a raw track, so the zone is resolved in one place.
     */
    public function storedPath(Video $video): string
    {
        return "{$video->downloadPrefix()}/{$this->relativePath()}";
    }

    /**
     * Where a retained `original` is filed once processing ends, out of the shared upload folder.
     *
     * It used to sit directly under `{videoUlid}/` on the theory that an unguessable stream ULID
     * kept it undownloadable. That theory was wrong: `Str::ulid()` is monotonic, so a ULID minted in
     * the same millisecond as the video's own is the very next value — making the key derivable from
     * the public video ULID. What keeps it unreachable now is the zone: no token is ever issued for
     * `original/`, and no manifest names anything in it.
     */
    public function archivePath(Video $video): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);

        return "{$video->originalPrefix()}/{$this->ulid}".($extension ? ".{$extension}" : '');
    }

    public function outputs(): BelongsToMany
    {
        return $this->belongsToMany(Output::class);
    }
}
