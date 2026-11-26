<?php

namespace App\Http\Controllers;

use App\Ai\ModelCatalog;
use App\Http\Responses\Api;
use App\Models\ModelChoice;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The approved AI models. Admins can read the list; only a Super Admin approves, edits, enables or removes one from use.
 * The detected list is read live from Hermes and is only shown here: people see the enabled, approved ones.
 */
class ModelChoiceController extends Controller
{
    private const NAME_RULE = '/^[A-Za-z0-9._:\\/-]+$/';

    public function __construct(private ModelCatalog $catalog) {}

    public function index(): JsonResponse
    {
        $agent = ModelCatalog::adminAgent();
        if (! $agent) {
            return Api::error('no_chatbot', 'Set up a chatbot first: models are read from its AI server.', 409);
        }
        $catalog = $this->catalog->for($agent);
        $choices = ModelChoice::orderBy('label')->orderBy('id')->get();
        $approved = $choices->map(fn ($c) => $c->provider.'|'.$c->model)->flip();

        return Api::ok([
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'live' => $catalog['live'],
            'choices' => $choices->map(fn ($c) => $this->present($c, $catalog['live'] ? $this->catalog->has($agent, $c->provider, $c->model) : null))->values(),
            'detected' => collect($catalog['models'])->map(fn ($m) => $m + ['approved' => $approved->has($m['provider'].'|'.$m['model'])])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'provider' => ['required', 'string', 'max:64', 'regex:'.self::NAME_RULE],
            'model' => ['required', 'string', 'max:100', 'regex:'.self::NAME_RULE],
            'label' => ['required', 'string', 'min:1', 'max:100'],
            'hint' => ['nullable', 'string', 'max:200'],
            'supports_images' => ['nullable', 'boolean'],
        ]);
        $agent = ModelCatalog::adminAgent();
        if (! $agent) {
            return Api::error('no_chatbot', 'Set up a chatbot first: models are read from its AI server.', 409);
        }
        if ($this->catalog->has($agent, $d['provider'], $d['model']) !== true) {
            return Api::error('model_not_detected', 'That model is not listed by the AI server right now. Refresh the list and choose one that is shown.', 422, ['model' => ['That model is not listed by the AI server right now.']]);
        }
        if (ModelChoice::where('provider', $d['provider'])->where('model', $d['model'])->exists()) {
            return Api::error('validation_failed', 'That model is already approved.', 422, ['model' => ['That model is already approved.']]);
        }

        $choice = ModelChoice::create([
            'provider' => $d['provider'], 'model' => $d['model'], 'label' => trim($d['label']), 'hint' => filled($d['hint'] ?? null) ? trim($d['hint']) : null,
            'supports_images' => (bool) ($d['supports_images'] ?? false), 'created_by' => $request->user()->id,
        ]);
        Audit::log('model_choice.created', $request->user(), $choice, ['model' => $choice->model, 'provider' => $choice->provider]);

        return Api::ok($this->present($choice->fresh(), true), status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $choice = ModelChoice::findOrFail($id);
        $d = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:100'],
            'hint' => ['sometimes', 'nullable', 'string', 'max:200'],
            'supports_images' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $enabled = $d['is_enabled'] ?? $choice->is_enabled;
        $default = ($d['is_default'] ?? $choice->is_default) && $enabled;
        if (($d['is_default'] ?? false) && ! $enabled) {
            return Api::error('default_needs_enabled', 'Only an enabled model can be the default.', 422);
        }

        DB::transaction(function () use ($choice, $d, $enabled, $default) {
            if ($default) {
                ModelChoice::where('id', '!=', $choice->id)->update(['is_default' => false]);
            }
            $choice->fill(array_intersect_key($d, array_flip(['label', 'hint', 'supports_images'])));
            $choice->is_enabled = $enabled;
            $choice->is_default = $default;
            $choice->save();
        });
        Audit::log('model_choice.updated', $request->user(), $choice, ['model' => $choice->model, 'is_enabled' => $choice->is_enabled, 'is_default' => $choice->is_default]);

        return Api::ok($this->present($choice->fresh(), null));
    }

    /** @return array<string, mixed> */
    private function present(ModelChoice $c, ?bool $available): array
    {
        return [
            'id' => $c->id, 'provider' => $c->provider, 'model' => $c->model, 'label' => $c->label, 'hint' => $c->hint,
            'supports_images' => $c->supports_images, 'is_enabled' => $c->is_enabled, 'is_default' => $c->is_default,
            'available' => $available,
        ];
    }
}
