<?php

namespace Coryrose\LivewireTables;

use Illuminate\Support\Str;
use Livewire\Component;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivewireModelTable extends Component
{
    public $sortColumn = null;
    public $sortField = null;
    public $sortDir = null;
    public $search = null;
    public $paginate = true;
    public $pagination = 10;
    public $hasSearch = true;
    
    public $css;

    protected $listeners = ['sortColumn' => 'setSort'];

    public function fields()
    {
        return [];
    }

    public function setSort($column)
    {
        $sortDirLookup = ["asc" => "desc", "desc" => null, null => "asc"];
        if ($column !== $this->sortColumn) {
            $this->sortColumn = $column;
            $this->sortDir = "asc";
        } else {
            $this->sortDir = $sortDirLookup[$this->sortDir];
            if (is_null($this->sortDir)) {
                $this->sortColumn = null;
            }
        }
    }

    protected function query()
    {
        return $this->paginate($this->buildQuery());
    }

    protected function querySql()
    {
        return $this->buildQuery()->toSql();
    }

    protected function buildQuery()
    {
        $model = app($this->model());
        $query = $model->newQuery();
        $queryFields = $this->generateQueryFields($model);
        if ($this->with()) {
            $query = $this->joinManyRelations($query, $model);
        }
        $query = $this->sort($query, $queryFields);
        $query->select("{$model->getTable()}.*");
        
        if ($this->hasSearch && $this->search && $this->search !== '') {
            $query = $this->search($query, $queryFields);
        }

        return $query;
    }

    protected function sort($query, $queryFields)
    {
        if (is_null($this->sortColumn) || is_null($this->sortDir)) {
            return $query;
        }
        $queryField = $queryFields[$this->sortColumn];
        if (array_key_exists("orderBy", $queryField)) {
            return $query->orderBy($queryField["orderBy"], $this->sortDir);
        } else if (array_key_exists("name", $queryField)) {
            return $query->orderBy($queryField["name"], $this->sortDir);
        } else if (array_key_exists("orderByRaw", $queryField)) {
            return $queryField["orderByRaw"]($query, $this->sortDir);
        }
    }

    protected function searchOneField($query, $searchField, $or = true)
    {
        if (array_key_exists('rawSearch', $searchField)) {
            return $searchField['rawSearch']($query);
        } elseif ($or) {
            return $query->orWhere($searchField['name'], 'LIKE', "%{$this->search}%");
        } else {
            return $query->where($searchField['name'], 'LIKE', "%{$this->search}%");
        }
    }

    protected function search($query, $queryFields)
    {
        $searchFields = $queryFields->where('searchable', true);
        $firstSearch = $searchFields->shift();
        $query = $this->searchOneField($query, $firstSearch, false);

        foreach ($searchFields as $searchField) {
            $query = $this->searchOneField($query, $searchField);
        }

        return $query;
    }

    protected function paginate($query)
    {
        if (!$this->paginate) {
            return $query->get();
        }

        return $query->paginate($this->pagination ?? 15);
    }

    public function model()
    { }

    protected function with()
    {
        return [];
    }

    public function clearSearch()
    {
        $this->search = null;
    }

    protected function joinManyRelations($query)
    {
        $query = $query->with($this->with());
        foreach ($this->with() as $with) {
            $model = app($this->model());
            foreach (explode(".", $with) as $relationship) {
                $relatedTable = $model->{$relationship}()->getRelated()->getTable();
                if (!collect($query->getQuery()->joins)->pluck('table')->contains($relatedTable)) {
                    $localTable = $model->getTable();
                    $localKey = $model->{$relationship}()->getForeignKeyName();
                    $relatedKey = $model->{$relationship}()->getOwnerKeyName();

                    if ($model->{$relationship}() instanceof BelongsTo) {
                        $query->leftJoin($relatedTable, "{$relatedTable}.{$relatedKey}", "{$localTable}.{$localKey}");
                    } else { // original join, not sure if correct?
                        $query->leftJoin($relatedTable, "{$localTable}.{$localKey}", "{$relatedTable}.{$localKey}");
                    }
                }
                $model = $model->{$relationship}()->getRelated();
            }
        }
        return $query;
    }

    protected function generateQueryFields($model)
    {
        return (collect($this->fields()))->transform(function ($selectField) use ($model) {
            if (array_key_exists("name", $selectField)) {
                if (Str::contains($selectField['name'], '.')) {
                    $relationships = explode(".", $selectField['name']);
                    for ($i = 0; $i < count($relationships) - 1; $i++) {
                        $select = "{$model->{$relationships[$i]}()->getRelated()->getTable()}.{$relationships[$i + 1]}";
                        $model = $model->{$relationships[$i]}()->getRelated();
                    }
                    $selectField['name'] = $select;
                } else {
                    $selectField['name'] = "{$model->getTable()}.{$selectField['name']}";
                }
            }
            return $selectField;
        });
    }
}
