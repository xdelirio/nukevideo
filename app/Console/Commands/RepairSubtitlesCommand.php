<?php

namespace App\Console\Commands;

use App\Jobs\PackageVideoJob;
use App\Models\Stream;
use App\Models\Video;
use App\Services\SubtitlePackager;
use App\Support\Scratch;
use App\Support\WebVtt;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Re-packages the subtitles of already-published videos and grafts them back into the manifests on
 * primary S3.
 *
 * Videos packaged before {@see WebVtt} landed can be serving manifests with no text tracks at all:
 * shaka fails its whole run over one unparseable input, so a single malformed cue dropped every
 * subtitle from the manifest even though the tracks encoded fine. Repacking needs neither the source
 * nor the mirror — both are long gone by then — because the raw `.vtt` files stay on primary S3
 * in the download zone ({@see Stream::storedPath}), which is all the text packager reads.
 *
 * The CDN keeps serving the old manifest until its TTL expires; there is no purge API wired up.
 */
class RepairSubtitlesCommand extends Command
{
    protected $signature = 'videos:repair-subtitles
        {video?* : Video ids or ULIDs; omit to scan every video that has subtitle tracks}
        {--dry-run : Report what would change, upload nothing}';

    protected $description = 'Re-package subtitles into the manifests of videos published without them';

    public function handle(SubtitlePackager $packager): int
    {
        $videos = $this->targets();

        if ($videos->isEmpty()) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($videos as $video) {
            if (! $this->repair($video, $packager)) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int,Video> */
    private function targets(): Collection
    {
        $keys = $this->argument('video');

        $query = Video::query()
            ->whereHas('streams', fn ($q) => $q->where('type', 'subtitle'))
            ->with(['streams' => fn ($q) => $q->where('type', 'subtitle')]);

        if ($keys) {
            return $query->where(fn ($q) => $q->whereIn('ulid', $keys)->orWhereIn('id', array_filter($keys, 'is_numeric')))->get();
        }

        $this->info('Scanning every video with subtitle tracks for manifests missing them…');

        return $query->orderBy('id')->get()->filter(fn (Video $v) => $this->missingText($v) !== [])->values();
    }

    /**
     * Manifest keys on S3 whose text tracks are missing. Reading the manifest is the only reliable
     * check: a failed run still leaves segment dirs behind for the tracks it got through before it
     * aborted, so their presence proves nothing.
     *
     * @return list<string>
     */
    private function missingText(Video $video): array
    {
        $disk = Storage::disk('s3');
        $missing = [];

        foreach ($disk->files($video->playPrefix()) as $key) {
            $isDash = str_ends_with($key, '.mpd');

            if (! $isDash && ! str_ends_with($key, '.m3u8')) {
                continue;
            }

            // The `_subs.*` throwaway is not an output; older runs left it behind on S3.
            if (str_starts_with(basename($key), '_')) {
                continue;
            }

            $body = (string) $disk->get($key);
            $hasText = $isDash ? str_contains($body, 'contentType="text"') : str_contains($body, 'TYPE=SUBTITLES');

            if (! $hasText) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function repair(Video $video, SubtitlePackager $packager): bool
    {
        $label = "Video {$video->id} ({$video->ulid})";
        $missing = $this->missingText($video);

        if ($missing === []) {
            $this->line("{$label}: manifests already carry their subtitles, skipping.");

            return true;
        }

        $disk = Storage::disk('s3');
        $workDir = Storage::disk('local')->path("repair-subtitles/{$video->ulid}");

        try {
            $subs = $this->stageTracks($video, $workDir);

            if ($subs === []) {
                $this->error("{$label}: no usable .vtt left on S3 — nothing to repackage.");

                return false;
            }

            foreach ($missing as $key) {
                $this->write("{$workDir}/".basename($key), (string) $disk->get($key));
            }

            $packager->packageAndGraft(
                $subs,
                $workDir,
                SubtitlePackager::segmentDurationFor($video),
                600,
                ['video' => $video->id],
            );

            $grafted = array_values(array_filter(
                $missing,
                fn ($key) => str_contains((string) file_get_contents("{$workDir}/".basename($key)), str_ends_with($key, '.mpd') ? 'contentType="text"' : 'TYPE=SUBTITLES')
            ));

            if ($grafted === []) {
                $this->error("{$label}: repackaging produced no text tracks — left untouched.");

                return false;
            }

            $tracks = count($subs);
            $this->line("{$label}: {$tracks} track(s) packaged into ".count($grafted).' manifest(s).');

            if ($this->option('dry-run')) {
                return true;
            }

            $this->upload($video, $workDir, $grafted);
            $this->info("{$label}: repaired.");

            return true;
        } finally {
            Storage::disk('local')->deleteDirectory("repair-subtitles/{$video->ulid}");
        }
    }

    /**
     * Pull each track's raw `.vtt` off S3 into the work dir under the same relative path the packager
     * expects, repairing it on the way ({@see WebVtt}) — an unrepaired one is exactly what broke the
     * original run. Mirrors {@see PackageVideoJob::subtitleInputs()}.
     *
     * @return list<array{path:string,type:string,ulid:string,height:?int,language:?string,forced:bool,hearing_impaired:bool,name:?string}>
     */
    private function stageTracks(Video $video, string $workDir): array
    {
        $disk = Storage::disk('s3');
        $inputs = [];

        foreach ($video->streams as $sub) {
            $key = $sub->storedPath($video);

            if (! $disk->exists($key)) {
                $this->warn("  stream {$sub->id}: {$key} is gone, skipping.");

                continue;
            }

            $vtt = WebVtt::sanitize((string) $disk->get($key));

            if (! str_contains($vtt, '-->')) {
                $this->warn("  stream {$sub->id}: no cues, skipping.");

                continue;
            }

            $path = "{$workDir}/{$sub->relativePath()}";
            $this->write($path, $vtt);

            $inputs[] = [
                'path' => $path,
                'type' => 'subtitle',
                'ulid' => $sub->ulid,
                'height' => null,
                'language' => $sub->language,
                'forced' => $sub->forced,
                'hearing_impaired' => (bool) data_get($sub->meta, 'hearing_impaired', false),
                'name' => $sub->name,
            ];
        }

        return $inputs;
    }

    /**
     * Push the segment dirs first and the manifests last, so no manifest ever advertises a segment
     * that has not landed yet. Content types are forced for the same reason the sync does it
     * ({@see PackageVideoJob::SYNC_CONTENT_TYPES}): the edge only signs types it knows.
     *
     * @param  list<string>  $manifests  S3 keys
     */
    private function upload(Video $video, string $workDir, array $manifests): void
    {
        $disk = Storage::disk('s3');

        foreach ($video->streams as $sub) {
            foreach (glob("{$workDir}/{$sub->ulid}/*") ?: [] as $segment) {
                $disk->put(
                    $sub->segmentsPath($video).'/'.basename($segment),
                    (string) file_get_contents($segment),
                    ['ContentType' => $this->contentType($segment)],
                );
            }
        }

        foreach ($manifests as $key) {
            $disk->put($key, (string) file_get_contents("{$workDir}/".basename($key)), [
                'ContentType' => $this->contentType($key),
            ]);
        }
    }

    private function contentType(string $path): string
    {
        return PackageVideoJob::SYNC_CONTENT_TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    private function write(string $path, string $contents): void
    {
        Scratch::ensureDirectory(dirname($path));

        file_put_contents($path, $contents);
    }
}
