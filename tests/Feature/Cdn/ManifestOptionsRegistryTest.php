<?php

use App\Data\Video\ManifestOptions;
use App\Enums\SubtitleFormat;
use App\Services\Cdn\ManifestOptionsRegistry;
use App\Support\LinkExpiry;
use Illuminate\Support\Facades\Cache;

/** A token as the edge hands it back, carrying `$key` the way the signer writes it. */
function tokenCarrying(?string $key): string
{
    return 'exp=1~acl=/v/play/*'.($key === null ? '' : "~mo={$key}").'~hmac=abc';
}

it('needs neither a key nor an entry for the defaults', function () {
    $registry = app(ManifestOptionsRegistry::class);

    expect($registry->keyFor(new ManifestOptions))->toBeNull()
        ->and($registry->keyFor(new ManifestOptions(subtitlesFormat: SubtitleFormat::VTT)))->toBeNull()
        ->and($registry->resolve(tokenCarrying(null))->isDefault())->toBeTrue()
        ->and((new ManifestOptions)->subtitlesFormat)->toBe(SubtitleFormat::VTT);
});

it('resolves the options a token names', function () {
    $registry = app(ManifestOptionsRegistry::class);

    $key = $registry->keyFor(new ManifestOptions(resolution: 720, subtitlesFormat: SubtitleFormat::SEGMENTS));
    $resolved = $registry->resolve(tokenCarrying($key));

    expect($key)->toMatch('/^[0-9a-f]{16}$/')
        ->and($resolved->resolution)->toBe(720)
        ->and($resolved->subtitlesFormat)->toBe(SubtitleFormat::SEGMENTS);
});

it('reads the key wherever it sits among the token fields', function () {
    $registry = app(ManifestOptionsRegistry::class);
    $key = $registry->keyFor(new ManifestOptions(resolution: 480));

    foreach (["exp=1~acl=/v/play/*~ip=1.2.3.4~limit=1000~mo={$key}~hmac=abc", "mo={$key}~exp=1~hmac=abc"] as $token) {
        expect($registry->resolve($token)->resolution)->toBe(480);
    }
});

it('answers the defaults for a token that names nothing it knows', function (?string $token) {
    app(ManifestOptionsRegistry::class)->keyFor(new ManifestOptions(resolution: 480));

    expect(app(ManifestOptionsRegistry::class)->resolve($token)->isDefault())->toBeTrue();
})->with([
    'no token' => [null],
    'an empty one' => [''],
    'no key in it' => ['exp=1~acl=/x/*~hmac=abc'],
    'a key nobody stored' => ['exp=1~mo=0123456789abcdef~hmac=abc'],
    'a key of the wrong shape' => ['exp=1~mo=../../etc~hmac=abc'],
]);

it('matches the field whole, never as the tail of another', function () {
    $registry = app(ManifestOptionsRegistry::class);
    $key = $registry->keyFor(new ManifestOptions(resolution: 480));

    // `demo=` ends in `mo=`.
    expect($registry->resolve("exp=1~demo={$key}~hmac=abc")->isDefault())->toBeTrue();
});

it('answers the defaults for an entry it can no longer read', function () {
    Cache::put(ManifestOptionsRegistry::cacheKey('0123456789abcdef'), ['subtitlesFormat' => 'gone'], 60);

    expect(app(ManifestOptionsRegistry::class)->resolve(tokenCarrying('0123456789abcdef'))->isDefault())->toBeTrue();
});

it('keeps one entry per set of options, however many links ask for it', function () {
    $registry = app(ManifestOptionsRegistry::class);

    $first = $registry->keyFor(new ManifestOptions(resolution: 720));
    $again = $registry->keyFor(new ManifestOptions(resolution: 720));
    $other = $registry->keyFor(new ManifestOptions(resolution: 480));

    expect($again)->toBe($first)
        ->and($other)->not->toBe($first)
        ->and($registry->keyFor(new ManifestOptions(resolution: 720, subtitlesFormat: SubtitleFormat::SEGMENTS)))->not->toBe($first);
});

it('outlives the longest link that can name it, and is renewed by each mint', function () {
    $registry = app(ManifestOptionsRegistry::class);
    $key = $registry->keyFor(new ManifestOptions(resolution: 480));

    // A week-long link minted now still resolves on its last day.
    $this->travel(LinkExpiry::MAX_SECONDS)->seconds();
    expect($registry->resolve(tokenCarrying($key))->resolution)->toBe(480);

    // Minted again then, by anyone asking for the same thing: good for another week.
    $registry->keyFor(new ManifestOptions(resolution: 480));
    $this->travel(LinkExpiry::MAX_SECONDS)->seconds();
    expect($registry->resolve(tokenCarrying($key))->resolution)->toBe(480);

    $this->travel(2)->days();
    expect($registry->resolve(tokenCarrying($key))->isDefault())->toBeTrue();
});
