<?php

namespace App\Data\Video;

use App\Enums\SubtitleFormat;
use App\Services\Cdn\ManifestOptionsRegistry;
use App\Services\Manifest\DynamicManifestService;
use App\Services\Manifest\ManifestTransformer;
use Spatie\LaravelData\Data;

/**
 * What one minted link asked its manifests to look like.
 *
 * Never part of a URL: the mint files it under the link's token
 * ({@see ManifestOptionsRegistry}) and the manifest is built from it when the edge asks
 * ({@see DynamicManifestService}). A viewer cannot change what they cannot see, and the token
 * does not grow with every option this gains.
 *
 * Adding a personalization is one property here and one {@see ManifestTransformer} reading it.
 * The defaults are what a link that asked for nothing gets, and they are not "the manifest as
 * packaged": subtitles go out as plain WebVTT unless a link asks for the packaged form. Only a
 * link that departs from them is stored, so a new property's default is also what every link
 * minted before it existed resolves to.
 */
class ManifestOptions extends Data
{
    public function __construct(
        /** Drop every video rendition above this (short edge, as `1080` means in either
         *  orientation). Null leaves the ladder whole. */
        public ?int $resolution = null,
        public SubtitleFormat $subtitlesFormat = SubtitleFormat::VTT,
    ) {}

    /**
     * What a link gets without asking, so nothing needs storing for it. Not the same as "nothing
     * to do": the default subtitle format is itself a transformation of the packaged manifest.
     */
    public function isDefault(): bool
    {
        return $this->resolution === null && $this->subtitlesFormat === SubtitleFormat::VTT;
    }

    /**
     * Identifies the manifest these options produce, so viewers who asked for the same thing share
     * one built copy. Derived from every value, so an option added later separates its variants
     * without anyone remembering to.
     */
    public function cacheKey(): string
    {
        $values = $this->toArray();
        ksort($values);

        return substr(hash('sha256', (string) json_encode($values)), 0, 16);
    }
}
