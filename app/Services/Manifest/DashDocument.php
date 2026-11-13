<?php

namespace App\Services\Manifest;

use App\Services\ManifestEditor;
use DOMDocument;
use DOMXPath;

/**
 * An MPD opened for editing, with the DASH namespace registered as `m`.
 *
 * Loaded and written the way {@see ManifestEditor} does it, so a manifest that
 * went through a transformer is formatted like one the editor touched.
 */
class DashDocument
{
    public const NS = 'urn:mpeg:dash:schema:mpd:2011';

    private function __construct(public DOMDocument $doc, public DOMXPath $xpath) {}

    /** Null when the body is not well-formed XML. */
    public static function load(string $xml): ?self
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::NS);

        return new self($doc, $xpath);
    }

    public function save(): string
    {
        return (string) $this->doc->saveXML();
    }
}
