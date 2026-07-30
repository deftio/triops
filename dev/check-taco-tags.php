<?php
/**
 * Assert that the browser's TACO allowlist and the published schema agree.
 *
 *     php dev/check-taco-tags.php
 *
 * The element allowlist necessarily exists twice: docs/taco-wire.schema.json is
 * what other people validate against, and app/assets/triops.js cannot read it
 * without a fetch triops does not want to make. Two hand-maintained lists drift
 * the moment nobody is looking, and the failure is silent and one-directional —
 * the renderer quietly accepts something the schema says it rejects. So diff
 * them in CI instead of building machinery to avoid the duplication.
 *
 * Exits non-zero and says which side has the extra tags.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$schema = json_decode((string) file_get_contents($root . '/docs/taco-wire.schema.json'), true);
if (!is_array($schema)) {
    fwrite(STDERR, "cannot parse docs/taco-wire.schema.json\n");
    exit(1);
}

$fromSchema = $schema['$defs']['node']['properties']['t']['enum'] ?? null;
if (!is_array($fromSchema)) {
    fwrite(STDERR, "no element enum at \$defs.node.properties.t.enum\n");
    exit(1);
}
sort($fromSchema);

$js = (string) file_get_contents($root . '/app/assets/triops.js');
if (preg_match('/var TACO_TAGS = (.*?);$/ms', $js, $m) !== 1) {
    fwrite(STDERR, "no TACO_TAGS declaration in app/assets/triops.js\n");
    exit(1);
}

// The declaration is a few single-quoted chunks joined with +, so collect the
// string literals and glue them back together.
preg_match_all("/'([^']*)'/", $m[1], $chunks);
$fromJs = preg_split('/\s+/', trim(implode('', $chunks[1]))) ?: [];
sort($fromJs);

if ($fromSchema === $fromJs) {
    echo 'renderer and schema agree on ' . count($fromJs) . " elements\n";
    exit(0);
}

fwrite(STDERR, "TACO_TAGS and taco-wire.schema.json disagree\n");
$onlySchema = array_diff($fromSchema, $fromJs);
$onlyJs     = array_diff($fromJs, $fromSchema);
if ($onlySchema) {
    fwrite(STDERR, '  only in the schema: ' . implode(' ', $onlySchema) . "\n");
}
if ($onlyJs) {
    fwrite(STDERR, '  only in triops.js:  ' . implode(' ', $onlyJs) . "\n");
}
exit(1);
