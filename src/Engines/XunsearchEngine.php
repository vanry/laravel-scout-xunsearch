<?php

namespace Vanry\Scout\Engines;

use XS;
use XSDocument;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use donatj\Ini\Builder as IniBuilder;

class XunsearchEngine extends Engine
{
    /**
     * The xs instance.
     *
     * @var \XS
     */
    protected $xs;

    /**
     * The xunsearch engine configurations.
     *
     * @var array
     */
    protected $config;

    /**
     * The scout search builder.
     *
     * @var \Laravel\Scout\Builder
     */
    protected $builder;

    /**
     * The default number of results to return for pagination.
     *
     * @var int
     */
    protected $perPage = 15;

    /**
     * Create a new engine instance.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     *
     * @return void
     */
    public function update($models)
    {
        $index = $this->initIndex($models->first());

        $index->openBuffer();

        $models->each(function ($model) use ($index) {
            $array = $model->toSearchableArray();

            if (empty($array)) {
                return;
            }

            $array = array_merge([$model->getKeyName() => $model->getKey()], $array);

            $index->update(new XSDocument($array));
        });

        $index->closeBuffer();

        $index->flushIndex();
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     *
     * @return void
     */
    public function delete($models)
    {
        $model = $models->first();

        $index = $this->initIndex($model);

        $index->del($models->pluck($model->getKeyName())->all());

        $index->flushIndex();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     *
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder->limit = $perPage;

        return $this->performSearch($builder, [
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $search = $this->initSearch($builder->model);

        if ($builder->callback) {
            return call_user_func($builder->callback, $search, $builder->query, $options);
        }

        $this->builder = $builder;

        $search->setFuzzy($this->config['fuzzy']);

        if ($builder->index) {
            $search->setProject($builder->index);
        }

        foreach ($builder->where as $field => $value) {
            $search->addRange($field, $value, $value);
        }

        foreach ($builder->orders as $order) {
            $search->setSort($order['column'], $order['direction']);
        }

        $limit = $builder->limit ?: $this->perPage;

        $offset = isset($options['page']) ? $options['page'] * $limit : 0;

        return $search->setLimit($limit, $offset)->search($builder->query);
     }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     *
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values()->all();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        $keys = $this->mapIds($results);

        return $model->whereIn($model->getQualifiedKeyName(), $keys)->get();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $id = $this->builder->model->getKeyName();

        return collect($results)->map(function ($document) use ($id) {
            return $document->$id;
        });
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     *
     * @return int
     */
    public function getTotalCount($results)
    {
        return $this->initSearch($this->builder->model)->getDbTotal();
    }

    /**
     * Get the XSIndex object initialized.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \XSIndex
     */
    public function initIndex($model)
    {
        return $this->initXunsearch($model)->getIndex();
    }

    /**
     * Get the XSSearch object initialized.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \XSSearch
     */
    public function initSearch($model)
    {
        return $this->initXunsearch($model)->getSearch();
    }

    /**
     * Get the XS object initialized.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \XS
     */
    public function initXunsearch($model)
    {
        if (is_null($this->xs)) {
            $ini = $this->buildIni($model);

            $this->xs = new XS($ini);
        }

        return $this->xs;
    }

    /**
     * Build ini string from configurations and model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    protected function buildIni($model)
    {
        $config['project.name'] = $model->searchableAs();

        $config['server.index'] = $this->config['hosts']['index'];
        $config['server.search'] = $this->config['hosts']['search'];

        foreach ($model->searchableSchema() as $field => $type) {
            $config[$field] = ['type' => $type];
        }

        return (new IniBuilder)->generate($config);
    }
}
