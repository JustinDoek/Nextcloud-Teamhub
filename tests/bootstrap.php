<?php
declare(strict_types=1);

/**
 * TeamHub unit-test bootstrap (v4.9.3).
 *
 * The host has no PHP; the tests run inside the Nextcloud container, where
 * `lib/base.php` boots a real server so every `OCP\…` interface resolves and
 * PHPUnit can mock it. The app's own classes are registered from the copy
 * under test — `docker cp` of the working copy — rather than from the
 * deployed one, so a test never silently exercises last deploy's code.
 *
 *   docker cp lib   nextcloud-aio-nextcloud:/tmp/teamhub-test/lib
 *   docker cp tests nextcloud-aio-nextcloud:/tmp/teamhub-test/tests
 *   docker exec -u www-data nextcloud-aio-nextcloud \
 *       php /tmp/phpunit.phar -c /tmp/teamhub-test/tests/phpunit.xml
 *
 * `NEXTCLOUD_ROOT` overrides the server root for a non-AIO layout.
 */

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($ncRoot . '/lib/base.php')) {
    fwrite(STDERR, "Nextcloud not found at {$ncRoot} — set NEXTCLOUD_ROOT\n");
    exit(1);
}

require_once $ncRoot . '/lib/base.php';

// The app's classes, from the copy this bootstrap lives in. NC ≤ 34 has the
// static on OC_App; NC 35 moved it to the AppManager (found 2026-09-18 when
// the AIO instance updated itself to 35.0.0 and every test died in bootstrap).
// Dev tooling only, so reaching the private class through the container is
// acceptable here where it is not in lib/.
if (method_exists(\OC_App::class, 'registerAutoloading')) {
    \OC_App::registerAutoloading('teamhub', dirname(__DIR__));
} else {
    \OC::$server->get(\OCP\App\IAppManager::class)->registerAutoloading('teamhub', dirname(__DIR__));
}
// The tests' own base classes (PHPUnit only auto-includes *Test.php).
\OC::$composerAutoloader->addPsr4('OCA\\TeamHub\\Tests\\', __DIR__ . '/', true);

// The test double for the official app's service. The tests always script
// OpenProject through it (the real class needs the whole app wired up); when
// the real app is not installed, the double is aliased to the real name so
// `OpenProjectClient`'s `class_exists()` guard sees a class either way.
require_once __DIR__ . '/Stubs/OpenProjectAPIService.php';
if (!class_exists('OCA\\OpenProject\\Service\\OpenProjectAPIService')) {
    class_alias('OCA\\TeamHub\\Tests\\Stubs\\OpenProjectAPIService', 'OCA\\OpenProject\\Service\\OpenProjectAPIService');
}
