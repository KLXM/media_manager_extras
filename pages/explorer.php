<?php

$addon = rex_addon::get('media_manager_extras');
$service = rex_media_manager_service::factory();
$sql = rex_sql::factory();

/** @var array<int, array{name: string, description: string}> $typeRows */
$typeRows = $sql->getArray('SELECT name, description FROM ' . rex::getTable('media_manager_type') . ' ORDER BY name ASC');
$effects = $service->listAvailableEffects();
sort($effects);

$selectedType = rex_request('mmx_type', 'string', '');
$selectedEffect = rex_request('mmx_effect', 'string', '');

$typeInfo = null;
$typeError = '';
if ($selectedType !== '') {
    try {
        $typeInfo = $service->getParamsForType($selectedType);
    } catch (Throwable $throwable) {
        $typeError = $throwable->getMessage();
    }
}

$effectInfo = null;
$effectError = '';
if ($selectedEffect !== '') {
    try {
        $effectInfo = $service->getParamsForEffect($selectedEffect);
    } catch (Throwable $throwable) {
        $effectError = $throwable->getMessage();
    }
}

echo rex_view::info($addon->i18n('media_manager_extras_explorer_intro'));

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">' . rex_escape($addon->i18n('media_manager_extras_explorer_filter_title')) . '</h3></div>';
echo '<div class="panel-body">';
echo '<form method="get" action="' . rex_escape(rex_url::currentBackendPage()) . '" class="form-horizontal">';
echo '<input type="hidden" name="page" value="' . rex_escape((string) rex_request('page', 'string')) . '">';
echo '<input type="hidden" name="subpage" value="' . rex_escape((string) rex_request('subpage', 'string', 'explorer')) . '">';

echo '<div class="form-group">';
echo '<label class="col-sm-2 control-label" for="mmx_type">' . rex_escape($addon->i18n('media_manager_extras_explorer_type_label')) . '</label>';
echo '<div class="col-sm-10">';
echo '<select class="form-control" id="mmx_type" name="mmx_type">';
echo '<option value="">' . rex_escape($addon->i18n('media_manager_extras_explorer_type_placeholder')) . '</option>';
foreach ($typeRows as $typeRow) {
    $name = (string) $typeRow['name'];
    $description = (string) $typeRow['description'];
    $selected = $name === $selectedType ? ' selected' : '';
    $label = $description !== '' ? $name . ' - ' . $description : $name;
    echo '<option value="' . rex_escape($name) . '"' . $selected . '>' . rex_escape($label) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div class="form-group">';
echo '<label class="col-sm-2 control-label" for="mmx_effect">' . rex_escape($addon->i18n('media_manager_extras_explorer_effect_label')) . '</label>';
echo '<div class="col-sm-10">';
echo '<select class="form-control" id="mmx_effect" name="mmx_effect">';
echo '<option value="">' . rex_escape($addon->i18n('media_manager_extras_explorer_effect_placeholder')) . '</option>';
foreach ($effects as $effectName) {
    $selected = $effectName === $selectedEffect ? ' selected' : '';
    echo '<option value="' . rex_escape($effectName) . '"' . $selected . '>' . rex_escape($effectName) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div class="form-group">';
echo '<div class="col-sm-offset-2 col-sm-10">';
echo '<button class="btn btn-primary" type="submit">' . rex_escape($addon->i18n('media_manager_extras_explorer_submit')) . '</button>';
echo '</div>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

if ($typeError !== '') {
    echo rex_view::error(rex_escape($typeError));
}

if (is_array($typeInfo)) {
    $typeDescription = (string) ($typeInfo['description'] ?? '');
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . rex_escape($addon->i18n('media_manager_extras_explorer_type_result_title', (string) $typeInfo['type'])) . '</h3></div>';
    echo '<div class="panel-body">';

    if ($typeDescription !== '') {
        echo '<p><strong>' . rex_escape($addon->i18n('media_manager_extras_explorer_description')) . ':</strong> ' . rex_escape($typeDescription) . '</p>';
    }

    $ensureCode = $service->dumpTypeInfo($typeInfo);
    echo '<h4>' . rex_escape($addon->i18n('media_manager_extras_explorer_ensure_code_title')) . '</h4>';
    echo '<p>' . rex_escape($addon->i18n('media_manager_extras_explorer_ensure_code_notice')) . '</p>';
    echo '<textarea class="form-control" rows="18" readonly>' . rex_escape($ensureCode) . '</textarea>';

    echo '<hr>';
    echo '<h4>' . rex_escape($addon->i18n('media_manager_extras_explorer_effects_title')) . '</h4>';

    foreach ($typeInfo['effects'] as $effect) {
        $priority = (int) $effect['priority'];
        $effectName = (string) $effect['effect'];
        $configured = is_array($effect['configured']) ? $effect['configured'] : [];
        $schema = is_array($effect['schema']) ? $effect['schema'] : [];

        echo '<div class="panel panel-info">';
        echo '<div class="panel-heading"><strong>#' . $priority . ' ' . rex_escape($effectName) . '</strong></div>';
        echo '<div class="panel-body">';

        echo '<h5>' . rex_escape($addon->i18n('media_manager_extras_explorer_configured_values')) . '</h5>';
        echo '<pre>' . rex_escape((string) json_encode($configured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';

        echo '<h5>' . rex_escape($addon->i18n('media_manager_extras_explorer_schema')) . '</h5>';
        echo '<table class="table table-striped table-condensed">';
        echo '<thead><tr>';
        echo '<th>' . rex_escape($addon->i18n('media_manager_extras_explorer_col_param')) . '</th>';
        echo '<th>' . rex_escape($addon->i18n('media_manager_extras_explorer_col_type')) . '</th>';
        echo '<th>' . rex_escape($addon->i18n('media_manager_extras_explorer_col_default')) . '</th>';
        echo '<th>' . rex_escape($addon->i18n('media_manager_extras_explorer_col_options')) . '</th>';
        echo '<th>' . rex_escape($addon->i18n('media_manager_extras_explorer_col_notice')) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($schema as $paramName => $paramConfig) {
            $paramType = is_array($paramConfig) ? ($paramConfig['type'] ?? null) : null;
            $paramDefault = is_array($paramConfig) ? ($paramConfig['default'] ?? null) : null;
            $paramOptions = is_array($paramConfig) ? ($paramConfig['options'] ?? null) : null;
            $paramNotice = is_array($paramConfig) ? ($paramConfig['notice'] ?? null) : null;

            echo '<tr>';
            echo '<td><code>' . rex_escape((string) $paramName) . '</code></td>';
            echo '<td>' . rex_escape((string) $paramType) . '</td>';
            echo '<td><code>' . rex_escape((string) json_encode($paramDefault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code></td>';
            echo '<td><code>' . rex_escape((string) json_encode($paramOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code></td>';
            echo '<td>' . rex_escape((string) $paramNotice) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
}

if ($effectError !== '') {
    echo rex_view::error(rex_escape($effectError));
}

if (is_array($effectInfo)) {
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . rex_escape($addon->i18n('media_manager_extras_explorer_effect_result_title', (string) $effectInfo['name'])) . '</h3></div>';
    echo '<div class="panel-body">';
    echo '<pre>' . rex_escape((string) json_encode($effectInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
    echo '</div>';
    echo '</div>';
}
