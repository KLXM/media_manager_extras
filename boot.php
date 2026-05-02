<?php
/**
 * Media Manager Helper
 *
 * @author KLXM Crossmedia GmbH
 * @author Thomas Skerbis
 */

// Effect für Media Manager registrieren
if (rex::isBackend() && rex::getUser()) {
    rex_media_manager::addEffect('rex_effect_srcset_helper');
}

// Falls Frontend: Output-Filter für srcset-Verarbeitung registrieren
if (rex::isFrontend()) {
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) {
        $content = $ep->getSubject();
        $content = \KLXM\MediaManagerExtras\ResponsiveImage::replaceMediaTags($content);
        $ep->setSubject($content);
    });
}
