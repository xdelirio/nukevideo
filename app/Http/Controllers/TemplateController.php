<?php

namespace App\Http\Controllers;

use App\Data\Template\ReorderTemplatesData;
use App\Data\Template\StoreTemplateData;
use App\Data\Template\UpdateTemplateData;
use App\Data\TemplateData;
use App\Data\TemplatePresetData;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['enabled' => 'sometimes|in:1,0,true,false']);

        $templates = $request->project()->templates()
            ->with('currentVersion')
            ->when($request->has('enabled'), fn ($query) => $query->where('enabled', $request->boolean('enabled')))
            ->ordered()
            ->get();

        return response()->json(['data' => $templates->map(fn ($t) => TemplateData::fromModel($t))->all()]);
    }

    public function show(Request $request, string $ulid)
    {
        $template = $request->project()->templates()->where('ulid', $ulid)->firstOrFail();

        return response()->json(['data' => TemplateData::fromModel($template)]);
    }

    public function store(Request $request, StoreTemplateData $data)
    {
        $project = $request->project();

        $template = $project->templates()->create(
            $data->toDatabase() + ['user_id' => $project->user_id]
        );

        return response()->json(['data' => TemplateData::fromModel($template)]);
    }

    public function update(Request $request, UpdateTemplateData $data, string $ulid)
    {
        $template = $request->project()->templates()->findOrFailByUlid($ulid);

        $template->update($data->toDatabase());

        return response()->json([
            'data' => TemplateData::fromModel($template->fresh()),
            'message' => 'Template updated successfully',
        ]);
    }

    /**
     * Copy a template into a new one, so a variant of a working encoding profile does not have to be
     * rebuilt output by output. The copy lands at the end of the order and keeps the original's
     * retention flags; it is deliberately independent — videos still point at the template they were
     * encoded with.
     */
    public function duplicate(Request $request, string $ulid)
    {
        $project = $request->project();

        $template = $project->templates()->findOrFailByUlid($ulid);

        $copy = $project->templates()->create([
            'name' => $this->copyName($project, $template->name),
            'query' => $template->query,
            'enabled' => $template->enabled,
            'keep_processed_files' => $template->keep_processed_files,
            'keep_original' => $template->keep_original,
            'user_id' => $project->user_id,
        ]);

        return response()->json([
            'data' => TemplateData::fromModel($copy),
            'message' => 'Template duplicated successfully',
        ]);
    }

    /**
     * Persist the panel's drag-and-drop order. The payload is the full list, so a partial one would
     * renumber a subset on top of the rest; every ULID has to resolve inside the caller's project
     * before anything is written.
     */
    public function reorder(Request $request, ReorderTemplatesData $data)
    {
        $project = $request->project();

        $known = $project->templates()->whereIn('ulid', $data->ulids)->pluck('ulid');

        if ($known->count() !== count($data->ulids)) {
            abort(422, 'The list contains templates that do not belong to this project.');
        }

        // The project scope is repeated on the write itself: setNewOrder() matches on the column it
        // is given, and `ulid` is unique but not tenant-bound by the query it builds.
        Template::setNewOrder(
            $data->ulids,
            primaryKeyColumn: 'ulid',
            modifyQuery: fn ($query) => $query->where('project_id', $project->id),
        );

        return response()->json([
            'data' => $project->templates()->with('currentVersion')->ordered()->get()
                ->map(fn ($t) => TemplateData::fromModel($t))->all(),
            'message' => 'Templates reordered successfully',
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $template = $request->project()->templates()->where('ulid', $ulid)->firstOrFail();

        // Videos keep a reference to the template they were encoded with; deleting it under
        // them used to cascade the whole library away.
        if ($template->videos()->exists()) {
            abort(422, 'This template has videos encoded with it. Delete them first.');
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted successfully']);
    }

    public function presets()
    {
        $presets = collect(config('template-presets'))->map(function ($preset, $slug) {
            return [
                'slug' => $slug,
                'name' => $preset['name'],
                'description' => $preset['description'],
                'category' => $preset['category'],
                'query' => $preset['query'],
            ];
        })->values();

        return response()->json(['data' => TemplatePresetData::collect($presets->all())]);
    }

    public function adoptPreset(Request $request, string $slug)
    {
        $presets = config('template-presets');

        if (! isset($presets[$slug])) {
            abort(404, 'Preset not found.');
        }

        $preset = $presets[$slug];

        $project = $request->project();

        $template = $project->templates()->create([
            'name' => $preset['name'],
            'query' => $preset['query'],
            'user_id' => $project->user_id,
        ]);

        return response()->json(['data' => TemplateData::fromModel($template)]);
    }

    public function getConfig()
    {
        return response()->json(['data' => [
            'codecs' => collect(config('ffmpeg.codecs'))->map($this->camelizeKeys(...))->all(),
            // Parameter names stay snake_case: they are the keys persisted in the template JSON.
            'parameters' => collect(config('ffmpeg.parameters'))->map($this->camelizeKeys(...))->all(),
        ]]);
    }

    /** `Name (copy)`, then `Name (copy 2)` and up, so duplicating twice does not yield two twins. */
    private function copyName(Project $project, string $name): string
    {
        $candidate = Str::limit("{$name} (copy)", 255, '');

        for ($n = 2; $project->templates()->where('name', $candidate)->exists(); $n++) {
            $candidate = Str::limit("{$name} (copy {$n})", 255, '');
        }

        return $candidate;
    }

    private function camelizeKeys(array $attributes): array
    {
        return collect($attributes)->mapWithKeys(fn ($value, $key) => [Str::camel($key) => $value])->all();
    }
}
