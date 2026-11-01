<?php

namespace App\Data\Stream;

use App\Data\RequestData;
use App\Services\CreateVideoStreamsService;
use App\Services\ManifestEditor;
use App\Services\PackagerCommandBuilder;
use App\Services\TrackIngestService;
use Closure;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Symfony\Component\Intl\Languages;

class UpdateStreamData extends RequestData
{
    /** Every writer of a track name holds it to this ({@see TrackIngestService}). */
    public const NAME_PATTERN = '/^[^",\r\n]+\z/u';

    public function __construct(
        /**
         * A comma would break the shaka stream descriptor this is interpolated into, and a quote or
         * a newline would break out of the HLS `#EXT-X-MEDIA` line it is written into — the master
         * playlist is edited as text, so a newline here would inject arbitrary tags for every viewer
         * ({@see PackagerCommandBuilder}, {@see ManifestEditor}).
         */
        #[Max(255), Regex(self::NAME_PATTERN)]
        public string $name,
        public ?string $language,
        public bool $forced,
        /**
         * Null means "not sent" — the flag keeps whatever the probe (or a previous edit) set.
         * The mapper is mandatory: rules are keyed on the *input* name, so without it the
         * inferred `boolean` rule lands under `hearing_impaired` and never runs against the
         * `hearingImpaired` key the panel sends — the value still reaches the constructor and
         * a non-bool blows up there as a 500 instead of a 422.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?bool $hearingImpaired = null,
    ) {}

    /** Same BCP-47 criterion the ingest applies ({@see CreateVideoStreamsService::sourceLanguage}). */
    public static function rules(): array
    {
        return [
            'language' => [
                'nullable',
                // `\z`, not `$`: PCRE's `$` also matches before a trailing newline.
                'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*\z/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $primary = strtolower(explode('-', (string) $value)[0]);

                    if (! Languages::exists($primary) && ! Languages::alpha3CodeExists($primary) && ! self::isSpecialLanguage($primary)) {
                        $fail('The :attribute is not a known language.');
                    }
                },
            ],
        ];
    }

    /**
     * ISO 639-2 codes that name no language but are what an honest label needs: `und` (unknown),
     * `mul` (several), `zxx` (no linguistic content — music, effects), `mis` (uncoded), and the
     * `qaa`–`qtz` range reserved for local use (a director's commentary, say). CLDR lists none.
     */
    public static function isSpecialLanguage(string $code): bool
    {
        return in_array($code, ['und', 'mul', 'zxx', 'mis'], true)
            || (strlen($code) === 3 && $code >= 'qaa' && $code <= 'qtz');
    }
}
