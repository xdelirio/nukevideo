<?php

use App\Data\Template\StoreTemplateData;
use App\Services\CreateVideoStreamsService;
use Illuminate\Support\Facades\Validator;

function templateQuery(array $variant, string $codec): array
{
    return ['outputs' => [[
        'video_codec' => $codec,
        'variants' => [$variant],
        'audio' => ['audio_codec' => 'aac', 'channels' => [['channels' => '2', 'audio_bitrate' => '128k']]],
    ]]];
}

function validateTemplate(array $variant, string $codec): bool
{
    return Validator::make(
        ['name' => 'x', 'query' => templateQuery($variant, $codec)],
        StoreTemplateData::rules(),
    )->passes();
}

// A GPU variant with neither a quality value nor a bitrate would fall into the encoder's
// default rate control (CQP default QP on QSV, 2M VBR on NVENC) — never what anyone wants.
it('requires a quality value on GPU variants unless a bitrate is pinned', function (string $codec) {
    expect(validateTemplate(['width' => 1920, 'height' => 1080], $codec))->toBeFalse();
})->with(['h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc']);

it('accepts quality mode and ABR mode GPU variants', function (string $codec, array $extra) {
    expect(validateTemplate(['width' => 1920, 'height' => 1080] + $extra, $codec))->toBeTrue();
})->with([
    ['av1_qsv', ['qsv_global_quality' => 22]],
    ['av1_qsv', ['constant_bitrate' => '3000k']],
    ['av1_nvenc', ['nvenc_cq' => 28]],
    ['av1_nvenc', ['constant_bitrate' => '3000k']],
]);

it('validates every shipped preset', function () {
    foreach (config('template-presets') as $slug => $preset) {
        expect(Validator::make(
            ['name' => 'x', 'query' => $preset['query']],
            StoreTemplateData::rules(),
        )->passes())->toBeTrue("preset {$slug} failed validation");
    }
});

/**
 * The codec belongs to the output. A variant or a channel entry that names its own used to win at
 * encode time while every check — the parameter rules, the hardware a video is routed to — went by
 * the output's. A tenant's `h264_nvenc` variant under a `libx264` output was claimed by the CPU
 * fleet and queued for GPUs that are not there, holding a dispatch slot for hours; `png` encoded
 * near-lossless chunks until the disk filled.
 */
function validateQuery(array $query): bool
{
    return Validator::make(['name' => 'x', 'query' => $query], StoreTemplateData::rules())->passes();
}

it('refuses a variant that names a codec of its own', function (string $key, string $value) {
    expect(validateTemplate(['width' => 1280, 'height' => 720, 'crf' => 23, $key => $value], 'libx264'))->toBeFalse();
})->with([
    ['video_codec', 'h264_nvenc'],
    ['video_codec', 'png'],
    ['video_codec', 'libx264'],
    ['audio_codec', 'libopus'],
]);

it('refuses a channel entry that carries anything but its channels and bitrate', function (array $entry) {
    $query = templateQuery(['width' => 1280, 'height' => 720, 'crf' => 23], 'libx264');
    $query['outputs'][0]['audio']['channels'] = [$entry];

    expect(validateQuery($query))->toBeFalse();
})->with([
    'a codec of its own' => [['channels' => '2', 'audio_bitrate' => '128k', 'audio_codec' => 'flac']],
    'an unvalidated parameter' => [['channels' => '2', 'audio_bitrate' => '128k', 'audio_profile' => 'x']],
]);

it('answers a malformed variant with a validation error, not a crash', function (mixed $variant) {
    $query = templateQuery([], 'libx264');
    $query['outputs'][0]['variants'] = [$variant];

    expect(validateQuery($query))->toBeFalse();
})->with(['a string' => ['x'], 'a number' => [7], 'null' => [null]]);

it('bounds how many outputs and variants one template may fan an upload out into', function () {
    $variant = ['width' => 1280, 'height' => 720, 'crf' => 23];
    $output = templateQuery($variant, 'libx264')['outputs'][0];

    expect(validateQuery(['outputs' => array_fill(0, 10, $output)]))->toBeTrue()
        ->and(validateQuery(['outputs' => array_fill(0, 11, $output)]))->toBeFalse()
        ->and(validateQuery(['outputs' => [['variants' => array_fill(0, 20, $variant)] + $output]]))->toBeTrue()
        ->and(validateQuery(['outputs' => [['variants' => array_fill(0, 21, $variant)] + $output]]))->toBeFalse();
});

it('encodes every audio rung with the block\'s codec, whatever a stored rung says', function () {
    // Stored templates are not re-validated, so the encode side holds the same line on its own.
    $rungs = CreateVideoStreamsService::audioRungs(
        ['audio_codec' => 'aac', 'channels' => [['channels' => '2', 'audio_bitrate' => '128k', 'audio_codec' => 'flac']]],
        2, 'aac', 'LC', 128000,
    );

    expect($rungs[2]['audio_codec'])->toBe('aac');
});
