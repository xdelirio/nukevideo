<?php

use App\Services\SubtitlePackager;
use Illuminate\Support\Facades\Process;

/**
 * Shaka fails its ENTIRE run over one unparseable input, so the joint run has to fall back to one
 * run per track — otherwise a single bad subtitle strips every track from the manifest.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/subs-'.uniqid();
    mkdir($this->dir);
    file_put_contents("{$this->dir}/output.mpd", '<MPD/>');
    file_put_contents("{$this->dir}/output.m3u8", '#EXTM3U');

    $this->subs = collect(['a', 'b', 'c'])->map(fn ($ulid) => [
        'path' => "{$this->dir}/{$ulid}.vtt",
        'type' => 'subtitle',
        'ulid' => $ulid,
        'language' => 'en',
        'forced' => false,
        'name' => strtoupper($ulid),
    ])->all();
});

afterEach(function () {
    array_map('unlink', glob("{$this->dir}/*") ?: []);
    rmdir($this->dir);
});

/** Record how many inputs each packager invocation carried, and decide its exit code by that count. */
function fakePackager(ArrayObject $runs, callable $succeeds): void
{
    Process::fake(function ($process) use ($runs, $succeeds) {
        $command = is_array($process->command) ? $process->command : [(string) $process->command];
        $inputs = count(array_filter($command, fn ($arg) => str_starts_with((string) $arg, 'in=')));
        $runs[] = $inputs;

        return $succeeds($inputs)
            ? Process::result('ok')
            : Process::result(output: '', errorOutput: 'PARSER_FAILURE', exitCode: 1);
    });
}

it('packages every track in a single run when they all parse', function () {
    fakePackager($runs = new ArrayObject, fn () => true);

    app(SubtitlePackager::class)->packageAndGraft($this->subs, $this->dir, 100, 60);

    // One run per format, three tracks each — no per-track retry.
    expect($runs->getArrayCopy())->toBe([3, 3]);
});

it('falls back to one run per track when the joint run fails', function () {
    // Only the joint run fails, exactly as a single malformed cue makes it.
    fakePackager($runs = new ArrayObject, fn (int $inputs) => $inputs === 1);

    app(SubtitlePackager::class)->packageAndGraft($this->subs, $this->dir, 100, 60);

    expect($runs->getArrayCopy())->toBe([3, 1, 1, 1, 3, 1, 1, 1]);
});

it('does not retry a lone track that fails', function () {
    fakePackager($runs = new ArrayObject, fn () => false);

    app(SubtitlePackager::class)->packageAndGraft([$this->subs[0]], $this->dir, 100, 60);

    expect($runs->getArrayCopy())->toBe([1, 1]);
});

it('leaves the plain .vtt of each packaged track next to its segments', function () {
    // The packager creates a track's segment directory; the fake stands in for the tracks it
    // got through. `b` is the one it refuses, and gets no directory and no sidecar.
    foreach (['a', 'b', 'c'] as $ulid) {
        file_put_contents("{$this->dir}/{$ulid}.vtt", "WEBVTT\n\n00:01.000 --> 00:02.000\n{$ulid}\n");
    }

    Process::fake(function ($process) {
        $inputs = array_values(array_filter($process->command, fn ($arg) => str_starts_with((string) $arg, 'in=')));

        if (count($inputs) !== 1 || str_contains($inputs[0], '/b.vtt')) {
            return Process::result(output: '', errorOutput: 'PARSER_FAILURE', exitCode: 1);
        }

        preg_match('#in=.*/(\w+)\.vtt#', $inputs[0], $track);
        @mkdir("{$this->dir}/{$track[1]}");

        return Process::result('ok');
    });

    app(SubtitlePackager::class)->packageAndGraft($this->subs, $this->dir, 100, 60);

    expect(file_get_contents("{$this->dir}/a/sub.vtt"))->toBe(file_get_contents("{$this->dir}/a.vtt"))
        ->and(is_file("{$this->dir}/c/sub.vtt"))->toBeTrue()
        ->and(is_dir("{$this->dir}/b"))->toBeFalse();

    foreach (['a', 'c'] as $ulid) {
        unlink("{$this->dir}/{$ulid}/sub.vtt");
        rmdir("{$this->dir}/{$ulid}");
    }
});
