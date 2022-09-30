<?php
/*

## Configuration

- **cachetool:socket:tcp** *(optional)*: accepts a *string* with IP address and port to the php-fpm TCP socket

    ```php
    set('cachetool:socket:tcp', '127.0.0.1:9000');
    ```

- **cachetool:socket:glob** *(optional)*: accepts a path or glob pattern to the php-fpm unix socket(s)

    ```php
    set('cachetool:socket:glob', '/var/run/php-*.sock');
    ```
    
Another alternative for multiple sockets is to specify them directly as array:

```php
set('cachetool:sockets', [
    '/var/run/php-domain-a.sock',
    '/var/run/php-domain-b.sock',
]);
```

This will override any `cachetool:socket:tcp` or `cachetool:socket:glob` configuration.

If neither of the above configuration values is given, then the tool will look for a `cachetool.yml` file in the current or any parent directory and read the configuration from there. See [cachetool documentation](https://github.com/gordalina/cachetool#configuration-file)

You can also specify different cachetool settings for each host:
```php
host('staging')
    ->set('cachetool:socket:tcp', '127.0.0.1:9000');

host('production')
    ->set('cachetool:socket:glob', '/var/run/php-fpm.sock');
```

If your deployment user does not have permission to access the socket, you can alternatively use
the web adapter that creates a temporary php file and makes a web request to it with a configuration like
```php
set('cachetool_args', '--web --web-path=./public --web-url=https://{{hostname}}');
```

In this case, do not set `cachetool:socket:tcp` or `cachetool:socket:glob`.

## Usage

Since APCu and OPcache deal with compiling and caching files, they should be executed right after the symlink is created for the new release:

```php
after('deploy:symlink', 'cachetool:clear:opcache');
// or
after('deploy:symlink', 'cachetool:clear:apcu');
```

## Read more

Read more information about cachetool on the website:
http://gordalina.github.io/cachetool/
 */
namespace Deployer;

set('cachetool:socket:tcp', '');
set('cachetool:socket:glob', '');
set('cachetool_url', 'https://github.com/gordalina/cachetool/releases/download/7.0.0/cachetool.phar');
set('cachetool_args', '');
set('bin/cachetool', function () {
    if (!test('[ -f {{release_or_current_path}}/cachetool.phar ]')) {
        run("cd {{release_or_current_path}} && curl -sLO {{cachetool_url}}");
    }
    return '{{release_or_current_path}}/cachetool.phar';
});
set('cachetool:sockets', function() {
    // Old single socket option for backwards compatibility
    if (has('cachetool')) {
        return [get('cachetool')];
    }
    // Socket via TCP
    if (has('cachetool:socket:tcp')) {
        return [get('cachetool:socket:tcp')];
    }
    // Socket via path
    if (has('cachetool:socket:glob')) {
        $socketPathGlob = get('cachetool:socket:glob');
        return explode(PHP_EOL, run("ls {$socketPathGlob}"));
    }
    return [];
});

/**
 * Clear opcache cache
 */
desc('Clears OPcode cache');
task('cachetool:clear:opcache', function () {
    if (count(get('cachetool:sockets')) === 0) {
        // if no socket is configured, use cachetool_args (if empty, cachetool looks for cachetool.yml configuration)
        run("cd {{release_or_current_path}} && {{bin/php}} {{bin/cachetool}} opcache:reset {{cachetool_args}}");
        return;
    }
    foreach (get('cachetool:sockets') as $socket) {
        // executing opcache_reset too fast repeatedly fails. We try with increasing wait times
        $waitSeconds = 0;
        $waitSecondIncrement = 1;
        $attempts = 0;
        $maxAttempts = 5;
        $success = false;
        do {
            try {
                run("{{bin/cachetool}} opcache:reset --fcgi=$socket");
                writeln("<info>Trying to reset PHP OpCache from socket $socket</info>");
                $success = true;
            } catch (\Exception $e) {
                writeln("<comment>Warning: PHP OpCache from socket $socket is not reset!</comment>");
                $waitSeconds += $waitSecondIncrement;
                $attempts++;
                if ($attempts < $maxAttempts) {
                    writeln("<comment>Retry in {$waitSeconds} seconds...</comment>");
                    sleep($waitSeconds);
                } else {
                    writeln("<error>Error: PHP OpCache from {$socket} could not be reset after {$maxAttempts} attempts</error>");
                }
            }
        } while (!$success && ($attempts < $maxAttempts));
    }

});

/**
 * Clear APCU cache
 */
desc('Clears APCu system cache');
task('cachetool:clear:apcu', function () {
    run("cd {{release_or_current_path}} && {{bin/php}} {{bin/cachetool}} apcu:cache:clear {{cachetool_options}}");
});

/**
 * Clear file status cache, including the realpath cache
 */
desc('Clears file status and realpath caches');
task('cachetool:clear:stat', function () {
    run("cd {{release_or_current_path}} && {{bin/php}} {{bin/cachetool}} stat:clear {{cachetool_options}}");
});
