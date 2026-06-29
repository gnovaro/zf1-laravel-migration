<?php

namespace App\Services\Zf1\Transpiler;

use App\Services\Zf1\Contracts\TranspilerInterface;

class ModelTranspiler implements TranspilerInterface
{
    public function transpile(array $parsedData): string
    {
        $namespace = $this->buildNamespace($parsedData);
        $className = $parsedData['class'];
        $tableName = $parsedData['table_name'] ?? strtolower($className);
        $primaryKey = $parsedData['primary_key'] ?? 'id';
        $timestamps = 'false';
        $relations = $this->buildRelations($parsedData);

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Model;

class {$className} extends Model
{
    protected \$table = '{$tableName}';
    protected \$primaryKey = '{$primaryKey}';
    public \$timestamps = {$timestamps};
{$relations}
}
PHP;
    }

    private function buildNamespace(array $data): string
    {
        if (!empty($data['app_name'])) {
            return "App\\Models\\{$data['app_name']}\\{$data['module_name']}";
        }

        return "App\\Models";
    }

    private function buildRelations(array $data): string
    {
        $relations = '';

        if (!empty($data['reference_map'])) {
            foreach ($data['reference_map'] as $ruleName => $rule) {
                $relation = $this->mapReferenceToRelation($ruleName, $rule, $data);
                if ($relation !== null) {
                    $relations .= $relation;
                }
            }
        }

        if (!empty($data['dependent_tables'])) {
            foreach ($data['dependent_tables'] as $dep) {
                $relations .= $this->mapDependentToRelation($dep, $data);
            }
        }

        return $relations;
    }

    private function mapReferenceToRelation(string $ruleName, array $rule, array $data): string
    {
        $refTableClass = $rule['refTableClass'] ?? null;
        $columnsProp = $rule['columns'] ?? '?';
        $refColumnsProp = $rule['refColumns'] ?? 'id';

        if ($refTableClass === null) {
            return '';
        }

        $relatedModel = $this->classToModel($refTableClass);

        $methodName = lcfirst($ruleName);
        $camelRule = str_replace(' ', '', ucwords(str_replace('_', ' ', $methodName)));

        return <<<PHP

    public function {$camelRule}()
    {
        return \$this->belongsTo({$relatedModel}::class, '{$columnsProp}', '{$refColumnsProp}');
    }
PHP;
    }

    private function mapDependentToRelation(string $dependentClass, array $data): string
    {
        $modelName = $this->classToModel($dependentClass);
        $methodName = lcfirst(class_basename($dependentClass));

        return <<<PHP

    public function {$methodName}()
    {
        return \$this->hasMany({$modelName}::class);
    }
PHP;
    }

    private function classToModel(string $class): string
    {
        $parts = explode('_', $class);

        return end($parts);
    }

    public function generateMigration(array $parsedData, array $columns): string
    {
        $tableName = $parsedData['table_name'] ?? strtolower($parsedData['class']);
        $columnDefs = $this->buildColumnDefinitions($columns);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
{$columnDefs}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
    }

    private function buildColumnDefinitions(array $columns): string
    {
        if (empty($columns)) {
            return "            \$table->id();\n            \$table->timestamps();";
        }

        $defs = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? '';
            $type = $column['type'] ?? 'string';
            $nullable = $column['nullable'] ?? false;
            $default = $column['default'] ?? null;
            $length = $column['length'] ?? null;
            $autoIncrement = $column['auto_increment'] ?? false;

            if ($autoIncrement) {
                $defs[] = "            \$table->id('{$name}');";
                continue;
            }

            $colDef = $this->mapColumnType($type, $name, $length);

            if ($nullable) {
                $colDef .= '->nullable()';
            }

            if ($default !== null && $default !== '') {
                $defaultVal = is_numeric($default) ? $default : "'{$default}'";
                $colDef .= "->default({$defaultVal})";
            }

            $defs[] = "            {$colDef};";
        }

        return implode("\n", $defs);
    }

    private function mapColumnType(string $type, string $name, ?int $length): string
    {
        $typeMap = [
            'int' => 'integer',
            'tinyint' => 'tinyInteger',
            'smallint' => 'smallInteger',
            'mediumint' => 'mediumInteger',
            'bigint' => 'bigInteger',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'varchar' => 'string',
            'char' => 'char',
            'text' => 'text',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'blob' => 'binary',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'date' => 'date',
            'time' => 'time',
            'year' => 'year',
            'enum' => 'string',
            'json' => 'json',
            'boolean' => 'boolean',
        ];

        $laravelType = $typeMap[strtolower($type)] ?? 'string';

        if ($length !== null && in_array($laravelType, ['string', 'char', 'integer'])) {
            return "\$table->{$laravelType}('{$name}', {$length})";
        }

        return "\$table->{$laravelType}('{$name}')";
    }

    public function transpileAll(array $parsedModels, string $appName, string $moduleName): array
    {
        $results = [];

        foreach ($parsedModels as $parsed) {
            $parsed['app_name'] = $appName;
            $parsed['module_name'] = $moduleName;
            $code = $this->transpile($parsed);

            $outputPath = $this->getOutputPath($parsed, $appName);

            $results[] = [
                'source' => $parsed['file_path'],
                'destination' => $outputPath,
                'code' => $code,
                'class' => $parsed['class'],
                'type' => 'model',
            ];
        }

        return $results;
    }

    private function getOutputPath(array $parsed, string $appName): string
    {
        $moduleName = $parsed['module_name'] ?? 'Default';

        return app_path("Models/{$appName}/{$moduleName}/{$parsed['class']}.php");
    }
}
