<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Shared index/show/destroy for the admin school-structure resources.
 * Subclasses add store/update with their own Form Request.
 */
abstract class AcademicController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** @var class-string<JsonResource> */
    protected string $resource;

    /** @var list<string> */
    protected array $searchable = [];

    /** @var list<string> */
    protected array $with = [];

    /** @var array<string, string> table => column that references this resource's id */
    protected array $referencedBy = [];

    protected string $orderBy = 'id';

    protected string $orderDirection = 'asc';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', $this->model);

        $query = $this->model::query()->with($this->with)->orderBy($this->orderBy, $this->orderDirection);
        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && $this->searchable !== []) {
            $term = '%'.Str::of(mb_strtolower($search))->replace(['!', '%', '_'], ['!!', '!%', '!_']).'%';
            $query->where(function (Builder $q) use ($term): void {
                foreach ($this->searchable as $column) {
                    $q->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        $this->filter($query, $request);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return $this->resource::collection($query->paginate($perPage));
    }

    public function show(int|string $id): JsonResource
    {
        $model = $this->model::query()->with($this->with)->findOrFail($id);
        Gate::authorize('view', $model);

        return new $this->resource($model);
    }

    public function destroy(int|string $id): JsonResponse
    {
        $model = $this->model::query()->findOrFail($id);
        Gate::authorize('delete', $model);
        $this->guardDelete($model);

        try {
            $model->delete();
        } catch (QueryException $e) {
            // Backstop for references the explicit guard does not list.
            $this->inUse($this->resourceKey());
        }

        return response()->json(null, 204);
    }

    /** Extra query filters (e.g. ?grade_level_id=). */
    protected function filter(Builder $query, Request $request): void {}

    /** Abort with 422 when the row is still referenced elsewhere. */
    protected function guardDelete(Model $model): void
    {
        foreach ($this->referencedBy as $table => $column) {
            if (DB::table($table)->where($column, $model->getKey())->exists()) {
                $this->inUse($this->resourceKey());
            }
        }
    }

    /** Key under academic.resources.* naming this resource in messages. */
    abstract protected function resourceKey(): string;

    protected function inUse(string $resource): never
    {
        abort(422, __('academic.in_use', ['resource' => __("academic.resources.$resource")]));
    }
}
