<?php

$addon = rex_addon::get('media_manager_extras');

echo rex_view::title($addon->i18n('media_manager_extras_title'));
rex_be_controller::includeCurrentPageSubPath();
