<?php

declare(strict_types=1);

require_once '/usr/share/php/Composer/InstalledVersions.php';

$autoloaders = [
    '/usr/share/php/Raiffeisenbank/autoload.php',
    '/usr/share/php/mServer/autoload.php',
    '/usr/share/php/Office365/autoload.php',
    '/usr/share/php/PohodaSQL/autoload.php'
];

foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
    }
}

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Pohoda\\RaiffeisenBank\\' => '/usr/lib/pohoda-raiffeisenbank/src/Pohoda/RaiffeisenBank/',
        'SpojeNet\\PohodaSQL\\'    => '/usr/share/php/PohodaSQL/'
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
});

(function (): void {
    $versions = [];
    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $name    = 'unknown';
    $version = '0.0.0';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();
