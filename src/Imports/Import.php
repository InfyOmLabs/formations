<?php

namespace HeadlessLaravel\Formations\Imports;

use HeadlessLaravel\Formations\Field;
use HeadlessLaravel\Formations\Mail\ImportErrorsMail;
use HeadlessLaravel\Formations\Mail\ImportSuccessMail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class Import implements ToCollection, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use SkipsFailures;
    use Importable;

    public $model;

    public $fields = [];

    private $totalRows;

    public function __construct($model, $fields)
    {
        $this->model = $model;

        $this->fields = $fields;
    }

    public function collection(Collection $rows)
    {
        $this->totalRows = $rows->count();
        $rows = $this->prepare($rows);

        foreach ($rows as $row) {
            $model = new $this->model();
            $model->fill($this->values($row));
            $model->save();
            if (method_exists($model, 'imported')) {
                $model->imported($row);
            }
        }
    }

    public function prepare(Collection $rows): Collection
    {
        $rows = $this->replaceSingleRelations($rows);
        $rows = $this->replaceMultipleRelations($rows);

        return $rows;
    }

    public function rules(): array
    {
        $rules = [];

        /** @var Field $field */
        foreach ($this->fields as $field) {
            if ($field->isMultiple()) {
                $rules["$field->key.*.$field->relationColumn"] = $field->rules;
            } else {
                $rules["*.$field->key"] = $field->rules;
            }
        }

        return $rules;
    }

    public function values($row): array
    {
        $keys = collect($this->fields)->filter(function (Field $field) {
            return !$field->isMultiple();
        })->pluck('key');

        return $row->only($keys)->toArray();
    }

    public function replaceSingleRelations(Collection $rows): Collection
    {
        $replacements = [];

        /** @var Field[] $relations */
        $relations = collect($this->fields)->filter->isRelation();

        // author, category, etc
        foreach ($relations as $relation) {
            $relationshipName = $relation->internal;

            $relationship = app($this->model)->$relationshipName();

            $values = $rows
                ->unique($relation->key)
                ->pluck($relation->key)
                ->toArray();

            $models = $relationship->getModel()
                ->whereIn($relation->relationColumn, $values)
                ->get();

            $display = $relation->relationColumn;

            foreach ($models as $model) {
                $replacements[] = [
                    'search_key' => $relation->key, // author
                    'search_value' => $model->$display, // frank
                    'replace_key' => $relationship->getForeignKeyName(), // author_id
                    'replace_value' => $model->getKey(), // 1
                ];
            }
        }

        foreach ($replacements as $replacement) {
            $rows->where(
                $replacement['search_key'], // author
                $replacement['search_value'] // frank
            )->each(function ($row) use ($replacement) {
                unset($row[$replacement['search_key']]); // author
                $row[$replacement['replace_key']] = $replacement['replace_value']; // ['author_id'] = 1
                // change author to author_id in fields for validation keys in value()
                foreach ($this->fields as $field) {
                    if ($field->key == $replacement['search_key']) {
                        $field->key = $replacement['replace_key'];
                    }
                }
            });
        }

        return $rows;
    }

    public function replaceMultipleRelations(Collection $rows): Collection
    {
        /** @var Field[] $multipleRelations */
        $multipleRelations = collect($this->fields)->filter->isMultiple();

        $replacedRows = collect([]);
        $rows->each(function (Collection &$row) use ($multipleRelations, $replacedRows) {
            $rowData = $row->toArray();
            foreach ($multipleRelations as $relation) {
                foreach ($rowData as $key => $value) {
                    $pattern = "/{$relation->key}.(\d+)";
                    if (!is_null($relation->relationColumn)) {
                        $pattern .= ".{$relation->relationColumn}/";
                    } else {
                        $pattern .= "/";
                    }
                    if (preg_match($pattern, $key, $matches)) {
                        if (!isset($rowData[$relation->key])) {
                            $rowData[$relation->key] = [];
                        }
                        if (count($matches) > 0) {
                            if (is_null($relation->relationColumn)) {
                                $rowData[$relation->key][intval($matches[1]) - 1] = $value;
                                unset($rowData[$relation->key.'.'.$matches[1]]);
                            } else {
                                $rowData[$relation->key][intval($matches[1]) - 1][$relation->relationColumn] = $value;
                                unset($rowData[$relation->key.'.'.$matches[1].'.'.$relation->relationColumn]);
                            }
                        }
                    }
                }
            }
            $replacedRows->add(collect($rowData));
        });

        return $replacedRows;
    }

    public function confirmation()
    {
        if (count($this->failures())) {
            Mail::to(Auth::user())->send(new ImportErrorsMail($this->failures()));

            return;
        }

        Mail::to(Auth::user())->send(new ImportSuccessMail($this->totalRows - $this->failures()->count()));
    }
}
