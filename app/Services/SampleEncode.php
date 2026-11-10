<?php

namespace App\Services;

use App\Models\Stream;
use App\Support\OomScore;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Encodes a slice of the source with a rendition's REAL chunk command. What the media engine makes
 * of a parameter set is the whole question both callers ask ({@see QualityBitrateProbe} what it
 * costs, {@see RenditionPreflight} whether it runs at all), so a hand-rolled variant would answer a
 * different one. The sample file never outlives the call.
 */
class SampleEncode
{
    /**
     * Per window. What makes a sample representative is how many places it looks, not how long it
     * looks at each: three 20s windows misread a grainy episode by up to 5.7 VMAF and 78% bitrate
     * against 24 windows spread over it, while eight shorter ones stayed within 1.5 VMAF — for the
     * same 60-odd seconds of encode.
     */
    public const SECONDS = 8;

    /** How many windows a sample spreads over the runtime; see {@see SECONDS}. */
    public const WINDOWS = 8;

    /** Under this there is no middle of the runtime to sample. */
    public const MIN_DURATION = 120;

    /** Per sample; a 20s encode never legitimately needs more. */
    public const TIMEOUT = 300;

    public function __construct(
        private Stream $stream,
        private string $sourcePath,
    ) {}

    /**
     * Window starts spread evenly across the runtime, clear of the opening and the credits at
     * either end, so a sample is representative footage rather than the intro.
     *
     * @return list<float>
     */
    public static function windows(float $duration): array
    {
        return array_map(
            fn (int $i) => round(min($duration * (0.06 + 0.88 * $i / (self::WINDOWS - 1)), $duration - self::SECONDS), 3),
            range(0, self::WINDOWS - 1),
        );
    }

    /**
     * What the source itself spent on one track over each window, in bytes — what a sample's
     * size has to be compared with. Windows land on the busy middle of a runtime as often as not
     * (on one episode they ran 1.09x the file's average), so against the file-wide average a
     * sample reads heavier than the rendition it stands for. Null for a window ffprobe couldn't
     * read; the caller falls back to the file-wide rate.
     *
     * @param  list<float>  $windows  starts, as {@see windows} returns them
     * @return array<int, ?int> window => bytes
     */
    public static function sourceBytes(string $sourcePath, int|string $track, array $windows): array
    {
        try {
            // ffmpeg's input `-ss` counts from the file's start_time; ffprobe's timestamps are
            // absolute. Offset the windows so both read the same stretch.
            $probe = Process::timeout(60)->run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=start_time', '-of', 'csv=p=0', $sourcePath,
            ]);
            $offset = is_numeric(trim($probe->output())) ? (float) trim($probe->output()) : 0.0;
        } catch (ProcessTimedOutException) {
            return array_map(fn () => null, $windows);
        }

        $bytes = [];

        foreach ($windows as $i => $start) {
            $bytes[$i] = self::windowBytes($sourcePath, $track, $offset + $start);
        }

        return $bytes;
    }

    /** One window of {@see sourceBytes}; null when it can't be read, a timeout included. */
    private static function windowBytes(string $sourcePath, int|string $track, float $from): ?int
    {
        try {
            $result = Process::timeout(60)->run([
                'ffprobe', '-v', 'error',
                '-select_streams', (string) $track,
                // From a second early to an ABSOLUTE end well past the window; the pts filter below
                // keeps exactly the window. A `+duration` end counts from the keyframe the read
                // seeks back to, so a long GOP ate into it: with a 30s GOP, a window 25s past its
                // keyframe counted 372 of its 500 packets. The early start is for containers that
                // seek by decode time (MPEG-TS): a frame decoded just before `from` but shown after
                // it was never read. The end slack covers B-frames arriving out of order.
                '-read_intervals', sprintf('%.3f%%%.3f', max(0.0, $from - 1), $from + self::SECONDS * 2),
                '-show_entries', 'packet=pts_time,size',
                '-of', 'csv=p=0',
                $sourcePath,
            ]);
        } catch (ProcessTimedOutException) {
            return null;
        }

        $total = 0;
        $seen = false;

        foreach (explode("\n", $result->successful() ? trim($result->output()) : '') as $line) {
            [$pts, $size] = array_pad(explode(',', $line), 2, null);

            if (is_numeric($pts) && is_numeric($size) && $pts >= $from && $pts < $from + self::SECONDS) {
                $total += (int) $size;
                $seen = true;
            }
        }

        return $seen ? $total : null;
    }

    public function run(float $start, float $seconds = self::SECONDS, ?Closure $tick = null): SampleResult
    {
        $format = (new ChunkTranscodeService($this->stream))->outputFormat();
        $path = sprintf('%s/sample_%s_%d.%s', dirname($this->sourcePath), $this->stream->id, (int) round($start * 1000), $format);

        $command = EncodeCommandBuilder::build(
            new Collection([$this->stream]),
            $this->sourcePath,
            [$this->stream->id => $path],
            $start,
            $start + $seconds,
        );

        // Wall time is measured here rather than by the callers: this is the one place the real
        // chunk command runs against real footage before the fan-out commits to a window length.
        $startedAt = microtime(true);

        try {
            $result = Process::timeout(self::TIMEOUT)->run(OomScore::wrap($command, OomScore::probe()), fn () => $tick ? $tick() : null);

            return new SampleResult(
                $command,
                $result->successful(),
                (int) (@filesize($path) ?: 0),
                trim($result->errorOutput() ?: $result->output()),
                microtime(true) - $startedAt,
            );
        } catch (ProcessTimedOutException) {
            Log::warning('Sample encode timed out', ['stream' => $this->stream->id, 'start' => $start]);

            return new SampleResult($command, false, 0, 'Sample encode timed out', microtime(true) - $startedAt);
        } finally {
            @unlink($path);
        }
    }
}
