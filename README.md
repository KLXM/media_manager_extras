# media_manager_extras

REDAXO-Addon mit zwei unabhängigen Komponenten:

| Komponente | Zweck |
|---|---|
| `rex_media_manager_service` | Idempotentes Anlegen/Verwalten von Typen und Effekten (analog zu `rex_sql_table`) |
| `KLXM\MediaManagerExtras\ResponsiveImage` | `srcset`-Placeholder-Auflösung in `img`- und `picture`-Tags |

---

## Inhalt

- [Installation](#installation)
- [Backend-Explorer](#backend-explorer)
- [Schnellstart](#schnellstart)
- [rex_media_manager_service](#rex_media_manager_service-1)
  - [Typen definieren](#typen-definieren)
  - [Effekte definieren](#effekte-definieren)
  - [Effekte auf mehrere Typen](#effekte-auf-mehrere-typen)
  - [Persistieren und Löschen](#persistieren-und-löschen)
  - [Introspection](#introspection)
  - [Import / Export](#import--export)
- [ResponsiveImage](#responsiveimage-1)
  - [img-Tags ausgeben](#img-tags-ausgeben)
  - [picture-Tags ausgeben](#picture-tags-ausgeben)
  - [Hilfsmethoden](#hilfsmethoden)
- [API-Referenz](#api-referenz)
  - [rex_media_manager_service](#api-rex_media_manager_service)
  - [KLXM\MediaManagerExtras\ResponsiveImage](#api-responsiveimage)

---

## Installation

Addon installieren und aktivieren. Es registriert automatisch:
- den Effekt **`srcset_helper`** im Media Manager
- den **Frontend-Output-Filter** für Placeholder-Auflösung (`srcset="rex_media_type=..."` → echte URLs)

---

## Backend-Explorer

Im Backend unter **Media Manager Extras → Schema Explorer**:

- Typen und Effekte auswählen
- konfigurierte Werte und Parameterschemas einsehen
- fertigen `ensure()`-Code automatisch generieren und kopieren

---

## Schnellstart

```php
// install.php des eigenen Addons
rex_media_manager_service::factory()
    ->ensureType('hero_default', 'Hero Standardbild')
    ->ensureEffect('hero_default', 'resize', [
        'width'         => 1600,
        'height'        => 900,
        'style'         => 'maximum',
        'allow_enlarge' => 'not_enlarge',
    ], 1)
    ->ensureEffect('hero_default', 'srcset_helper', [
        'srcset' => '640 640w, 960 960w, 1280 1280w, 1600 1600w',
    ], 2)
    ->ensure();
```

```php
// Template / Modul
echo \KLXM\MediaManagerExtras\ResponsiveImage::getPictureTag(
    rex_sql::factory()->getValue('filename'),
    'hero_default',
    [
        '(min-width: 80rem)' => 'hero_xl',
        ['media' => '(min-width: 48rem)', 'type' => 'hero_md'],
    ],
    ['alt' => rex_escape($alt), 'loading' => 'lazy'],
);
```

```php
// uninstall.php des eigenen Addons
rex_media_manager_service::factory()
    ->ensureType('hero_default')
    ->ensureType('hero_xl')
    ->ensureType('hero_md')
    ->uninstall();
```

---

## rex_media_manager_service

### Typen definieren

```php
$service = rex_media_manager_service::factory();

// Typ registrieren (wird beim ensure() angelegt oder aktualisiert)
$service->ensureType('hero_default', 'Hero Standardbild');
```

Mehrfachaufruf für denselben Namen aktualisiert nur die Beschreibung.

### Effekte definieren

```php
$service
    ->ensureType('hero_default', 'Hero Standardbild')
    // Effekt mit expliziter Priorität
    ->ensureEffect('hero_default', 'resize', [
        'width'         => 1600,
        'height'        => 900,
        'style'         => 'maximum',
        'allow_enlarge' => 'not_enlarge',
    ], 1)
    // Ohne Priorität: wird ans Ende gestellt
    ->ensureEffect('hero_default', 'srcset_helper', [
        'srcset' => '640 640w, 960 960w, 1280 1280w, 1600 1600w',
    ])
    ->ensure();
```

**Semantik der drei Varianten:**

| Methode | Verhalten |
|---|---|
| `ensureEffect()` | Setzt die gesamte Effektreihenfolge neu (Prioritäten) |
| `prependEffect()` | Fügt einen Effekt **vor** bestehende Effekte des Typs ein |
| `appendEffect()` | Fügt einen Effekt **hinter** bestehende Effekte des Typs ein |

```php
// Scharfzeichner vor alle bestehenden Effekte schieben
$service
    ->prependEffect('hero_default', 'filter_sharpen', ['amount' => 50])
    ->ensure();

// srcset-Helfer ans Ende hängen
$service
    ->appendEffect('hero_default', 'srcset_helper', ['srcset' => '640 640w, 1280 1280w'])
    ->ensure();
```

### Effekte auf mehrere Typen

`$types` kann ein Array von Typnamen **oder** ein Wildcard-Pattern sein (`"news_*"`):

```php
// Scharfzeichner an den Anfang mehrerer Typen
$service
    ->prependEffectToTypes(['hero_default', 'hero_stage'], 'filter_sharpen', ['amount' => 60])
    ->ensure();

// Wasserzeichen ans Ende aller news_*-Typen
$service
    ->appendEffectToTypes('news_*', 'insert_image', [
        'brandimage' => 'watermark.png',
        'hpos'       => 'center',
        'vpos'       => 'middle',
        'padding_x'  => -20,
        'padding_y'  => -20,
    ])
    ->ensure();
```

### Persistieren und Löschen

```php
// Konfiguration in DB schreiben + Cache leeren
$service->ensure();

// Typen aus DB entfernen (Standard-Verhalten bei uninstall)
$service
    ->ensureType('hero_default')
    ->uninstall();

// Typen beim uninstall() erhalten
$service
    ->ensureType('hero_default')
    ->keepTypesOnUninstall()
    ->uninstall(); // löscht nichts
```

### Introspection

**Parameterschema eines Effekts:**

```php
$schema = rex_media_manager_service::factory()->getParamsForEffect('resize');
// ['name' => 'resize', 'class' => 'rex_effect_resize', 'params' => [...]]
dump($schema['params']);
```

**Vollständiger Typ-Snapshot (konfigurierte Werte + Schema):**

```php
$info = rex_media_manager_service::factory()->getParamsForType('hero_default');
// ['type' => '...', 'description' => '...', 'effects' => [...]]
// pro Effekt: 'configured' + 'schema'
dump($info);
```

**PHP-Code für einen Typ generieren:**

```php
echo rex_media_manager_service::factory()->dumpType('hero_default');
// → fertiger ensureType/ensureEffect-Code für copy-paste
```

**Alle verfügbaren Effektnamen:**

```php
$effects = rex_media_manager_service::factory()->listAvailableEffects();
// ['convert', 'crop', 'filter_blur', 'resize', 'srcset_helper', ...]
```

### Import / Export

```php
$service = rex_media_manager_service::factory();

// Als JSON-String exportieren
$json = $service->exportToJson();

// Nur bestimmte Typen
$json = $service->exportToJson(['hero_default', 'hero_stage']);

// Direkt in Datei schreiben (gibt bool zurück)
$service->exportToFile(rex_path::addonData('my_addon', 'mm-types.json'));

// Aus Datei importieren und aktivieren
$service
    ->importFromJson(rex_path::addonData('my_addon', 'mm-types.json'))
    ->ensure();
```

---

## ResponsiveImage

Der Output-Filter ist automatisch aktiv und ersetzt Placeholder wie `srcset="rex_media_type=hero_default"` im Frontend per DOM-Verarbeitung durch echte srcset-URLs.

### img-Tags ausgeben

```php
use KLXM\MediaManagerExtras\ResponsiveImage;

// img-Tag mit srcset-Placeholder (wird vom Output-Filter aufgelöst)
echo ResponsiveImage::getImgTag('beispiel.jpg', 'hero_default', [
    'alt'     => 'Beispielbild',
    'loading' => 'lazy',
]);
```

SVG, PDF und EPS erhalten keinen srcset-Placeholder.

### picture-Tags ausgeben

`$sources` unterstützt zwei Formate:

```php
use KLXM\MediaManagerExtras\ResponsiveImage;

echo ResponsiveImage::getPictureTag(
    'beispiel.jpg',
    'hero_default',          // Fallback-Typ für <img src>
    [
        // Kurzform: Media-Query => Typ-Name
        '(min-width: 80rem)' => 'hero_xl',

        // Langform: mit optionaler eigener Datei und sizes
        ['media' => '(min-width: 48rem)', 'type' => 'hero_md', 'sizes' => '100vw'],
        ['media' => '(min-width: 48rem)', 'type' => 'hero_md', 'file' => 'anderes.jpg'],
    ],
    ['alt' => 'Hero', 'loading' => 'lazy'],
);
```

### Hilfsmethoden

```php
use KLXM\MediaManagerExtras\ResponsiveImage;

// Prüfen ob Datei kein Pixel-Format (svg/pdf/eps)
ResponsiveImage::isNonPixelFormat('icon.svg'); // true

// srcset-Konfiguration eines Typs aus DB lesen
$config = ResponsiveImage::getSrcsetConfig('hero_default');
// [640 => '640w', 960 => '960w', 1280 => '1280w', 1600 => '1600w']

// Fertigen srcset-String erzeugen
$srcset = ResponsiveImage::getSrcsetString('hero_default', 'beispiel.jpg');

// srcset-Definitionsstring parsen
$parsed = ResponsiveImage::parseSrcsetString('640 640w, 960 960w, 1280 1280w');
// [640 => '640w', 960 => '960w', 1280 => '1280w']

// Placeholder im HTML manuell auflösen (läuft automatisch als Output-Filter)
$html = ResponsiveImage::replaceMediaTags($html);
```

---

## API-Referenz

### API: rex_media_manager_service

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `factory()` | `self` | Neue Instanz erstellen |
| `ensureType(string $name, string $description = '')` | `self` | Typ registrieren |
| `ensureEffect(string $type, string $effect, array $params = [], ?int $priority = null)` | `self` | Effekt mit Priorität setzen |
| `prependEffect(string $type, string $effect, array $params = [])` | `self` | Effekt an Anfang einfügen |
| `appendEffect(string $type, string $effect, array $params = [])` | `self` | Effekt an Ende anfügen |
| `prependEffectToTypes(string\|array $types, string $effect, array $params = [])` | `self` | Effekt vorne an mehrere Typen |
| `appendEffectToTypes(string\|array $types, string $effect, array $params = [])` | `self` | Effekt hinten an mehrere Typen |
| `keepTypesOnUninstall()` | `self` | Typen bei `uninstall()` erhalten |
| `ensure()` | `void` | Konfiguration in DB schreiben |
| `uninstall()` | `void` | Typen aus DB entfernen |
| `getParamsForEffect(string $effect)` | `array` | Parameterschema eines Effekts |
| `getParamsForType(string $type)` | `array` | Typ-Snapshot (konfiguriert + Schema) |
| `dumpType(string $type)` | `string` | PHP-Code für Typ generieren |
| `dumpTypeInfo(array $typeInfo)` | `string` | PHP-Code aus Typ-Array generieren |
| `listAvailableEffects()` | `list<string>` | Alle verfügbaren Effektnamen |
| `importFromJson(string $jsonFile)` | `self` | Typen aus JSON-Datei laden |
| `exportToJson(?array $typeNames, ?string $file, bool $prettyPrint, bool $includeSystemTypes)` | `string` | Als JSON exportieren |
| `exportToFile(string $file, ?array $typeNames, bool $prettyPrint, bool $includeSystemTypes)` | `bool` | Direkt in Datei exportieren |

`$types` bei `*ToTypes`-Methoden: Array von Namen **oder** Wildcard-Pattern wie `"news_*"`.

### API: ResponsiveImage

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `getImgTag(string $file, string $type, array $attributes = [])` | `string` | `img`-Tag mit srcset-Placeholder |
| `getImageByType(string $file, string $type, array $attributes = [])` | `string` | Alias für `getImgTag()` |
| `getPictureTag(string $file, string $defaultType, array $sources = [], array $imgAttributes = [])` | `string` | `picture`-Tag für Art Direction |
| `replaceMediaTags(string $html)` | `string` | Placeholder in HTML auflösen |
| `getSrcsetString(string $type, string $file)` | `string` | Fertigen srcset-Attributwert erzeugen |
| `getSrcsetConfig(string $type)` | `array<int, string>` | srcset-Konfiguration aus DB lesen |
| `parseSrcsetString(string $srcsetString)` | `array<int, string>` | Definitionsstring parsen |
| `isNonPixelFormat(string $file)` | `bool` | Prüfen ob SVG/PDF/EPS |
