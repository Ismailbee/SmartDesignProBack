<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $query = Template::query()->with('creator');

        foreach (['category', 'status'] as $field) {
            $value = $request->query($field);
            if ($value && ! in_array($value, ['all', ''], true)) {
                $query->where($field, $value);
            }
        }

        if ($accessLevel = $request->query('accessLevel')) {
            if (! in_array($accessLevel, ['all', ''], true)) {
                $query->where('access_level', $accessLevel);
            }
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $paginator = $query->latest()->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Template $template) => $this->transform($template))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'totalPages' => $paginator->lastPage(),
                'totalItems' => $paginator->total(),
                'itemsPerPage' => $paginator->perPage(),
                'hasNextPage' => $paginator->hasMorePages(),
                'hasPreviousPage' => $paginator->currentPage() > 1,
            ],
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'totalPages' => $paginator->lastPage(),
        ]);
    }

    public function pending(): JsonResponse
    {
        $templates = Template::query()->with('creator')->where('status', 'pending')->latest()->get();

        return response()->json(['success' => true, 'data' => $templates->map(fn (Template $template) => $this->transform($template))->values()->all()]);
    }

    public function show(string $id): JsonResponse
    {
        $template = Template::query()->with('creator')->find($id);

        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->transform($template)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = Template::query()->find($id);

        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'status' => ['nullable', 'string'],
            'accessLevel' => ['nullable', 'string'],
            'price' => ['nullable', 'integer'],
            'thumbnailUrl' => ['nullable', 'string'],
            'fileUrl' => ['nullable', 'string'],
        ]);

        $map = [
            'accessLevel' => 'access_level',
            'thumbnailUrl' => 'thumbnail_url',
            'fileUrl' => 'file_url',
        ];

        $updates = [];
        foreach ($data as $key => $value) {
            $updates[$map[$key] ?? $key] = $value;
        }

        $template->fill($updates)->save();

        return response()->json(['success' => true, 'data' => $this->transform($template->refresh()), 'message' => 'Template updated successfully']);
    }

    public function approve(string $id): JsonResponse
    {
        $template = Template::query()->find($id);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }
        $template->forceFill(['status' => 'published'])->save();
        return response()->json(['success' => true, 'message' => 'Template approved successfully']);
    }

    public function reject(string $id): JsonResponse
    {
        $template = Template::query()->find($id);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }
        $template->forceFill(['status' => 'rejected'])->save();
        return response()->json(['success' => true, 'message' => 'Template rejected successfully']);
    }

    public function destroy(string $id): JsonResponse
    {
        $template = Template::query()->find($id);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }
        $template->delete();
        return response()->json(['success' => true]);
    }

    private function transform(Template $template): array
    {
        return [
            'id' => $template->id,
            'title' => $template->title,
            'description' => $template->description,
            'category' => $template->category,
            'tags' => $template->tags ?? [],
            'creator' => [
                'id' => $template->creator?->api_id,
                'name' => $template->creator?->name,
                'email' => $template->creator?->email,
            ],
            'status' => $template->status,
            'accessLevel' => $template->access_level,
            'price' => (int) $template->price,
            'downloads' => (int) $template->downloads,
            'likes' => (int) $template->likes,
            'createdAt' => optional($template->created_at)?->toIso8601String(),
            'updatedAt' => optional($template->updated_at)?->toIso8601String(),
            'thumbnailUrl' => $template->thumbnail_url,
            'fileUrl' => $template->file_url,
        ];
    }
}