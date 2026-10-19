<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

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

    protected string $orderBy = 'id';

    protected string $orderDirection = 'asc';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', $this->model);

        $query = $this->model::query()->with($this->with)->orderBy($this->orderBy, $this->orderDirection);
        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && $this->searchable !== []) {
            $query->where(function (Builder $q) use ($search): void {
                foreach ($this->searchable as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
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
        $model->delete();

        return response()->json(null, 204);
    }

    /** Extra query filters (e.g. ?grade_level_id=). */
    protected function filter(Builder $query, Request $request): void {}

    /** Abort with 422 when the row is still referenced elsewhere. */
    protected function guardDelete(Model $model): void {}

    protected function inUse(string $resource): never
    {
        abort(422, __('academic.in_use', ['resource' => __("academic.resources.$resource")]));
    }
}
