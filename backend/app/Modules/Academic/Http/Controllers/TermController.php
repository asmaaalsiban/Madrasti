<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\TermRequest;
use App\Modules\Academic\Http\Resources\TermResource;
use App\Modules\Academic\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TermController extends AcademicController
{
    protected string $model = Term::class;

    protected string $resource = TermResource::class;

    protected array $referencedBy = ['assessments' => 'term_id', 'staff_evaluations' => 'term_id'];

    protected array $searchable = ['name'];

    protected array $with = ['academicYear'];

    protected string $orderBy = 'starts_on';

    protected string $orderDirection = 'desc';

    public function store(TermRequest $request): JsonResponse
    {
        Gate::authorize('create', Term::class);

        return (new TermResource(Term::create($request->validated())->load($this->with)))->response()->setStatusCode(201);
    }

    public function update(TermRequest $request, Term $term): TermResource
    {
        Gate::authorize('update', $term);
        $term->update($request->validated());

        return new TermResource($term->load($this->with));
    }

    protected function filter(Builder $query, Request $request): void
    {
        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->query('academic_year_id'));
        }
    }

    protected function resourceKey(): string
    {
        return 'term';
    }
}
