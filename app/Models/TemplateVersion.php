<?php

namespace App\Models;

use App\Models\Concerns\ReadsEncodingQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable revision of a template's encoding definition. Videos pin the one they were
 * ingested with ({@see Video::encodingQuery}), so editing a template only ever affects uploads that
 * come after the edit. Never updated after creation: a change is a new version.
 */
class TemplateVersion extends Model
{
    use ReadsEncodingQuery;

    protected $fillable = [
        'template_id',
        'version',
        'query',
    ];

    protected function casts()
    {
        return [
            'query' => 'json',
            'version' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
