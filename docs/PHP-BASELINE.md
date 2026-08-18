# Baseline de PHP en producción

Estado capturado antes de tocar la versión de PHP, para poder comparar después.
Si algo deja de funcionar tras el cambio, la respuesta está en el diff contra esto.

**Capturado:** 2026-08-17 · cPanel del colegio · CloudLinux alt-php

## Runtime

| | |
|---|---|
| Versión | 8.0.30 |
| SAPI web | litespeed |
| `php.ini` | `/opt/alt/php80/etc/php.ini` (mismo en web y CLI) |
| `sendmail_path` | `/usr/sbin/sendmail -t -i` |
| `disable_functions` | vacío |
| `mail()` | disponible |

CLI y web resuelven al mismo `php.ini`, así que `config:cache` y
`php artisan correo:probar` son fiables. Ver `DESPLIEGUE.md`.

## Módulos cargados (`php -m`, verdad del servidor)

```
bcmath    bz2       calendar  Core      ctype     curl      date
dba       dom       enchant   exif      fileinfo  filter    ftp
gd        gettext   gmp       hash      i360      iconv     imap
intl      json      ldap      libxml    mbstring  mysqlnd   odbc
openssl   pcntl     pcre      PDO       pdo_mysql PDO_ODBC  pdo_pgsql
pdo_sqlite pgsql    Phar      posix     pspell    readline  Reflection
session   shmop     SimpleXML soap      sockets   sodium    SPL
sqlite3   standard  sysvmsg   sysvsem   sysvshm   tidy      tokenizer
xml       xmlreader xmlwriter xsl       "Zend OPcache"       zip
zlib
```

```
[Zend Modules]
Zend OPcache
```

`i360` es Imunify360, se inyecta a nivel de servidor y no aparece como casilla
en cPanel. No la busques al comparar.

## Cambios hechos sobre el estado original

La captura inicial tenía `sodium` y `opcache` apagadas. Se activaron las dos
antes de tocar la versión de PHP:

| Extensión | Por qué se activó |
|---|---|
| `sodium` | `lcobucci/jwt` 4.3.0 la declara requisito duro. En ejecución el `SodiumBase64Polyfill` degradaba a `base64_encode`, así que no rompía nada, pero `composer install` habría fallado el chequeo de plataforma. |
| `opcache` | Estaba apagada — `[Zend Modules]` vacío lo confirmaba. Laravel recompilaba cada archivo en cada request. **Ver la nota de invalidación en `DESPLIEGUE.md`: con OPcache activa, desplegar código ya no es solo copiar ficheros.** |

Con esto, **todos** los requisitos de `composer.lock` están cubiertos.

## Notables que siguen APAGADAS

| Extensión | Consecuencia |
|---|---|
| `imagick` | `intervention/image` 2.7.2 usa GD por defecto, que sí está. Solo importaría si se cambia el driver. |
| `redis`, `memcached`, `igbinary` | Coherente con caché/sesión en fichero o BD. |
| `mysqli` / `nd_mysqli` | Ninguna activa. El proyecto usa PDO, así que no aplica. |

## MySQL: cuidado al migrar

En cPanel la casilla marcada es **`nd_pdo_mysql`**, y `pdo_mysql` está
desmarcada — pero `php -m` reporta el módulo como **`pdo_mysql`**. No es
contradicción: en alt-php las dos casillas producen el mismo módulo, compilado
contra distinta librería cliente (`nd_pdo_mysql` contra `mysqlnd`, `pdo_mysql`
contra `libmysqlclient`). Son alternativas, y **activar las dos rompe PHP**.

Al cambiar de versión, dejarlo igual: `nd_pdo_mysql` marcada, `pdo_mysql` no.
Es el fallo más probable del cambio, porque tumba toda la aplicación en vez de
una función suelta.

## Requisitos reales del proyecto

Extensiones que `composer.lock` declara:

```
ctype curl date dom fileinfo gd hash iconv json libxml mbstring
openssl pcre phar simplexml sodium spl tokenizer xml xmlreader
xmlwriter zip zlib
```

Todas presentes.

## Cómo comparar tras cambiar de versión

```bash
php -m > ~/ext-php80.txt          # ANTES de cambiar
# ...cambiar la versión en cPanel...
diff <(sort ~/ext-php80.txt) <(php -m | sort)
```

Las líneas con `<` son lo que se perdió.
