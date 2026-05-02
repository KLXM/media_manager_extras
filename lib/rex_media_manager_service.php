<?php

/**
 * @package redaxo\media-manager-extras
 */
class rex_media_manager_service
{
    /** @var array<string, array<string, mixed>> */
    private array $types = [];

    private bool $removeOnUninstall = true;

    public static function factory(): self
    {
        return new self();
    }

    public function ensureType(string $name, string $description = ''): self
    {
        if (!isset($this->types[$name])) {
            $this->types[$name] = [
                'name' => $name,
                'description' => $description,
                'ensureEffects' => [],
                'prependEffects' => [],
                'appendEffects' => [],
            ];
        } else {
            $this->types[$name]['description'] = $description;
        }

        return $this;
    }

    /** @param array<string, mixed> $params */
    public function ensureEffect(string $type, string $effect, array $params = [], ?int $priority = null): self
    {
        $this->ensureType($type);

        $normalized = $this->normalizeEffect($effect, $params);

        if ($priority === null || $priority <= 0) {
            $priority = count($this->types[$type]['ensureEffects']) + 1;
        }

        $this->types[$type]['ensureEffects'][$priority] = $normalized;

        return $this;
    }

    /** @param array<string, mixed> $params */
    public function prependEffect(string $type, string $effect, array $params = []): self
    {
        $this->ensureType($type);
        $this->types[$type]['prependEffects'][] = $this->normalizeEffect($effect, $params);

        return $this;
    }

    /** @param array<string, mixed> $params */
    public function appendEffect(string $type, string $effect, array $params = []): self
    {
        $this->ensureType($type);
        $this->types[$type]['appendEffects'][] = $this->normalizeEffect($effect, $params);

        return $this;
    }

    /**
     * @param string|array<int, string> $types
     * @param array<string, mixed> $params
     */
    public function prependEffectToTypes(string|array $types, string $effect, array $params = []): self
    {
        foreach ($this->resolveTypeNames($types) as $typeName) {
            $this->prependEffect($typeName, $effect, $params);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $types
     * @param array<string, mixed> $params
     */
    public function appendEffectToTypes(string|array $types, string $effect, array $params = []): self
    {
        foreach ($this->resolveTypeNames($types) as $typeName) {
            $this->appendEffect($typeName, $effect, $params);
        }

        return $this;
    }

    public function keepTypesOnUninstall(): self
    {
        $this->removeOnUninstall = false;
        return $this;
    }

    public function ensure(): void
    {
        if (!rex_addon::get('media_manager')->isAvailable()) {
            return;
        }

        $sql = rex_sql::factory();

        foreach ($this->types as $type) {
            $sql->setQuery('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = :name', ['name' => $type['name']]);

            if ($sql->getRows() > 0) {
                $typeId = (int) $sql->getValue('id');
                $sql->setTable(rex::getTable('media_manager_type'));
                $sql->setWhere(['id' => $typeId]);
                $sql->setValue('description', $type['description']);
                $sql->addGlobalUpdateFields();
                $sql->update();
            } else {
                $sql->setTable(rex::getTable('media_manager_type'));
                $sql->setValue('name', $type['name']);
                $sql->setValue('description', $type['description']);
                $sql->addGlobalCreateFields();
                $sql->insert();
                $typeId = (int) $sql->getLastId();
            }

            $finalEffects = null;

            if ($type['ensureEffects'] !== []) {
                ksort($type['ensureEffects']);
                $finalEffects = array_values($type['ensureEffects']);
            } elseif ($type['prependEffects'] !== [] || $type['appendEffects'] !== []) {
                /** @var list<array{effect: string, params: array<string, array<string, mixed>>}> $finalEffects */
                $finalEffects = array_merge(
                    $type['prependEffects'],
                    $this->getExistingEffects($typeId),
                    $type['appendEffects'],
                );
            }

            if ($finalEffects !== null) {
                $this->replaceEffects($typeId, $finalEffects);
            }
        }

        rex_media_manager::deleteCache();
    }

    public function uninstall(): void
    {
        if (!$this->removeOnUninstall || !rex_addon::get('media_manager')->isAvailable()) {
            return;
        }

        $sql = rex_sql::factory();
        foreach ($this->types as $type) {
            $sql->setQuery('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = :name', ['name' => $type['name']]);
            if ($sql->getRows() === 0) {
                continue;
            }

            $typeId = (int) $sql->getValue('id');
            $sql->setQuery('DELETE FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = ?', [$typeId]);
            $sql->setQuery('DELETE FROM ' . rex::getTable('media_manager_type') . ' WHERE id = ?', [$typeId]);
        }

        rex_media_manager::deleteCache();
    }

    /**
     * Returns the schema/params definition of an effect class.
     *
     * @return array{name: string, class: string, params: array<string, array{type: mixed, default: mixed, options: mixed, notice: mixed}>}
     */
    public function getParamsForEffect(string $effect): array
    {
        return $this->buildEffectParamsInfo($effect);
    }

    /**
     * Returns a type with all its configured effects and their schemas.
     *
     * @return array{type: string, description: string, effects: list<array{priority: int, effect: string, configured: array<string, mixed>, schema: array<string, array{type: mixed, default: mixed, options: mixed, notice: mixed}>}>}
     */
    public function getParamsForType(string $type): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT id, description FROM ' . rex::getTable('media_manager_type') . ' WHERE name = :name LIMIT 1',
            ['name' => $type],
        );

        if ($sql->getRows() === 0) {
            throw new rex_exception('Media manager type "' . $type . '" was not found');
        }

        $typeId = (int) $sql->getValue('id');
        $description = (string) $sql->getValue('description');

        $effectSql = rex_sql::factory();
        $rows = $effectSql->getArray(
            'SELECT effect, priority, parameters FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = :type_id ORDER BY priority ASC',
            ['type_id' => $typeId],
        );

        $effects = [];
        foreach ($rows as $row) {
            $effectName = (string) $row['effect'];
            $effects[] = [
                'priority' => (int) $row['priority'],
                'effect' => $effectName,
                'configured' => $this->extractConfiguredParams($effectName, (string) $row['parameters']),
                'schema' => $this->buildEffectParamsInfo($effectName)['params'],
            ];
        }

        return [
            'type' => $type,
            'description' => $description,
            'effects' => $effects,
        ];
    }

    /**
     * Generates ensure-code for a type loaded from the database.
     */
    public function dumpType(string $type): string
    {
        return $this->dumpTypeInfo($this->getParamsForType($type));
    }

    /**
     * Generates ensure-code from an already-loaded type-info array.
     *
     * @param array{type: string, description: string, effects: list<array{priority: int, effect: string, configured: array<string, mixed>, schema: mixed}>} $typeInfo
     */
    public function dumpTypeInfo(array $typeInfo): string
    {
        return $this->buildEnsureCodeFromTypeInfo($typeInfo);
    }

    /** @return list<string> */
    public function listAvailableEffects(): array
    {
        $effects = array_map(
            static fn(string $class) => str_replace('rex_effect_', '', $class),
            array_keys(rex_media_manager::getSupportedEffects()),
        );
        sort($effects);

        return $effects;
    }

    public function importFromJson(string $jsonFile): self
    {
        $json = rex_file::get($jsonFile);
        if (!is_string($json)) {
            throw new rex_exception('JSON file not found: ' . $jsonFile);
        }

        $types = json_decode($json, true);
        if (!is_array($types)) {
            throw new rex_exception('Invalid JSON file: ' . json_last_error_msg());
        }

        foreach ($types as $type) {
            if (!is_array($type) || !isset($type['name'])) {
                continue;
            }

            $name = (string) $type['name'];
            $description = (string) ($type['description'] ?? '');
            $this->ensureType($name, $description);

            foreach ((array) ($type['effects'] ?? []) as $priority => $effectData) {
                if (!is_array($effectData) || !isset($effectData['effect'])) {
                    continue;
                }

                $effectName = (string) $effectData['effect'];
                $effectKey = 'rex_effect_' . $effectName;
                $params = [];

                if (isset($effectData['params'][$effectKey]) && is_array($effectData['params'][$effectKey])) {
                    foreach ($effectData['params'][$effectKey] as $paramName => $paramValue) {
                        $params[str_replace($effectKey . '_', '', (string) $paramName)] = $paramValue;
                    }
                }

                $this->ensureEffect($name, $effectName, $params, (int) $priority);
            }
        }

        return $this;
    }

    /**
     * @param array<int, string>|null $typeNames
     */
    public function exportToJson(?array $typeNames = null, ?string $file = null, bool $prettyPrint = true, bool $includeSystemTypes = false): string
    {
        $sql = rex_sql::factory();

        $where = [];
        $params = [];

        if (!$includeSystemTypes) {
            $where[] = 'SUBSTR(name, 1, 10) != "rex_media_"';
        }

        if ($typeNames !== null && count($typeNames) > 0) {
            $escaped = [];
            foreach ($typeNames as $typeName) {
                $escaped[] = $sql->escape((string) $typeName);
            }
            $where[] = 'name IN (' . implode(',', $escaped) . ')';
        }

        $query = 'SELECT id, name, description FROM ' . rex::getTable('media_manager_type');
        if (count($where) > 0) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }
        $query .= ' ORDER BY name ASC';

        $types = $sql->getArray($query, $params);

        $export = [];
        foreach ($types as $type) {
            $typeId = (int) $type['id'];
            $effects = $sql->getArray(
                'SELECT effect, priority, parameters FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = :id ORDER BY priority ASC',
                ['id' => $typeId],
            );

            $exportEffects = [];
            foreach ($effects as $effect) {
                $exportEffects[(int) $effect['priority']] = [
                    'effect' => (string) $effect['effect'],
                    'params' => json_decode((string) $effect['parameters'], true),
                ];
            }

            $export[] = [
                'name' => (string) $type['name'],
                'description' => (string) $type['description'],
                'effects' => $exportEffects,
            ];
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = (string) json_encode($export, $flags);

        if ($file !== null && !rex_file::put($file, $json)) {
            throw new rex_exception('Could not write to file: ' . $file);
        }

        return $json;
    }

    /**
     * @param array<int, string>|null $typeNames
     */
    public function exportToFile(string $file, ?array $typeNames = null, bool $prettyPrint = true, bool $includeSystemTypes = false): bool
    {
        try {
            $this->exportToJson($typeNames, $file, $prettyPrint, $includeSystemTypes);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isEffectAvailable(string $effect): bool
    {
        return isset(rex_media_manager::getSupportedEffects()['rex_effect_' . $effect]);
    }

    /**
     * @return array{name: string, class: string, params: array<string, array{type: mixed, default: mixed, options: mixed, notice: mixed}>}
     */
    private function buildEffectParamsInfo(string $effect): array
    {
        if (!$this->isEffectAvailable($effect)) {
            throw new rex_exception('Effect "' . $effect . '" is not available');
        }

        $className = 'rex_effect_' . $effect;
        /** @var rex_effect_abstract $effectObj */
        $effectObj = new $className();

        $info = [
            'name' => $effect,
            'class' => $className,
            'params' => [],
        ];

        foreach ($effectObj->getParams() as $param) {
            $info['params'][$param['name']] = [
                'type' => $param['type'],
                'default' => $param['default'] ?? null,
                'options' => $param['options'] ?? null,
                'notice' => $param['notice'] ?? null,
            ];
        }

        return $info;
    }

    /** @return array<string, mixed> */
    private function extractConfiguredParams(string $effect, string $parametersJson): array
    {
        $configured = json_decode($parametersJson, true);
        if (!is_array($configured)) {
            return [];
        }

        $effectKey = 'rex_effect_' . $effect;
        if (!isset($configured[$effectKey]) || !is_array($configured[$effectKey])) {
            return [];
        }

        $result = [];
        foreach ($configured[$effectKey] as $key => $value) {
            $result[str_replace($effectKey . '_', '', (string) $key)] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{effect: string, params: array<string, array<string, mixed>>}
     */
    private function normalizeEffect(string $effect, array $params): array
    {
        if (!$this->isEffectAvailable($effect)) {
            throw new rex_exception('Effect "' . $effect . '" is not available');
        }

        $effectKey = 'rex_effect_' . $effect;
        /** @var rex_effect_abstract $obj */
        $obj = new $effectKey();
        $validKeys = array_map(
            static fn(array $p) => $effectKey . '_' . $p['name'],
            $obj->getParams(),
        );

        $normalizedParams = [];
        foreach ($params as $key => $value) {
            $fullKey = $effectKey . '_' . $key;
            if (!in_array($fullKey, $validKeys, true)) {
                throw new rex_exception('Unknown parameter "' . $key . '" for effect "' . $effect . '"');
            }

            $normalizedParams[$fullKey] = $value;
        }

        return ['effect' => $effect, 'params' => [$effectKey => $normalizedParams]];
    }

    /**
     * @return list<array{effect: string, params: array<string, array<string, mixed>>}>
     */
    private function getExistingEffects(int $typeId): array
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT effect, parameters FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = :type_id ORDER BY priority ASC',
            ['type_id' => $typeId],
        );

        return array_map(static function (array $row): array {
            $effectName = (string) $row['effect'];
            $parameters = json_decode((string) $row['parameters'], true);

            return [
                'effect' => $effectName,
                'params' => is_array($parameters) ? $parameters : ['rex_effect_' . $effectName => []],
            ];
        }, $rows);
    }

    /**
     * @param list<array{effect: string, params: array<string, array<string, mixed>>}> $effects
     */
    private function replaceEffects(int $typeId, array $effects): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery('DELETE FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = ?', [$typeId]);

        foreach ($effects as $index => $effect) {
            $sql->setTable(rex::getTable('media_manager_type_effect'));
            $sql->setValue('type_id', $typeId);
            $sql->setValue('effect', $effect['effect']);
            $sql->setValue('priority', $index + 1);
            $sql->setValue('parameters', (string) json_encode($effect['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $sql->addGlobalCreateFields();
            $sql->insert();
        }
    }

    /**
     * @param string|array<int, string> $types
     * @return list<string>
     */
    private function resolveTypeNames(string|array $types): array
    {
        if (is_array($types)) {
            return array_values(array_map(static fn(mixed $v) => (string) $v, $types));
        }

        if (!str_ends_with($types, '*')) {
            return [$types];
        }

        $pattern = rtrim($types, '*') . '%';
        $sql = rex_sql::factory();

        return array_map(
            static fn(mixed $v) => (string) $v,
            array_column(
                $sql->getArray('SELECT name FROM ' . rex::getTable('media_manager_type') . ' WHERE name LIKE :pattern', ['pattern' => $pattern]),
                'name',
            ),
        );
    }

    private function phpValue(mixed $value, int $level = 0): string
    {
        return match (true) {
            is_string($value) => '\'' . addslashes($value) . '\'',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            !is_array($value) => var_export($value, true),
            $value === [] => '[]',
            default => $this->phpArray($value, $level),
        };
    }

    /** @param array<mixed> $array */
    private function phpArray(array $array, int $level): string
    {
        $isList = array_is_list($array);
        $indent = str_repeat('    ', $level);
        $inner = str_repeat('    ', $level + 1);

        $lines = array_map(
            fn(mixed $key, mixed $item) => $inner
                . ($isList ? '' : '\'' . addslashes((string) $key) . '\' => ')
                . $this->phpValue($item, $level + 1) . ',',
            array_keys($array),
            $array,
        );

        return '[' . "\n" . implode("\n", $lines) . "\n" . $indent . ']';
    }

    /**
     * @param array{type: string, description: string, effects: list<array{priority: int, effect: string, configured: array<string, mixed>, schema: mixed}>} $typeInfo
     */
    private function buildEnsureCodeFromTypeInfo(array $typeInfo): string
    {
        $type = $typeInfo['type'];
        $description = $typeInfo['description'];

        $lines = [
            'rex_media_manager_service::factory()',
            '    ->ensureType(' . $this->phpValue($type) . ', ' . $this->phpValue($description) . ')',
        ];

        foreach ($typeInfo['effects'] as $effect) {
            $effectName = (string) $effect['effect'];
            $priority = (int) $effect['priority'];
            $configured = $effect['configured'];

            $paramsCode = $this->phpValue($configured, 2);
            if (str_contains($paramsCode, "\n")) {
                $paramsCode = "\n" . $paramsCode . "\n    ";
            }

            $lines[] = '    ->ensureEffect('
                . $this->phpValue($type) . ', '
                . $this->phpValue($effectName) . ', '
                . $paramsCode . ', '
                . $priority
                . ')';
        }

        $lines[] = '    ->ensure();';

        return implode("\n", $lines);
    }
}