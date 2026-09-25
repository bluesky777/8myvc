<?php
// Mide una petición: estado, consultas, ms, ms de SQL, bytes y sha1 de la respuesta. Todo dentro de una
// transacción que se deshace. Uso y porqué: docs/migracion/48-los-informes-pesados.md §Cómo se midió.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
require (getenv('BASE') ?: '/app').'/vendor/autoload.php';
$app = require_once (getenv('BASE') ?: '/app').'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
function pedir($m, $uri, $cuerpo, $token, $tx = true) {
  global $kernel;
  $r = Request::create($uri, $m, $cuerpo ?? [], [], [], ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'10.9.'.rand(1,250).'.'.rand(1,250)] + ($token ? ['HTTP_AUTHORIZATION'=>'Bearer '.$token] : []), $cuerpo === null ? null : json_encode($cuerpo));
  $n = 0; $porForma = [];
  DB::listen(function ($q) use (&$n, &$porForma) { $n++; $GLOBALS["sqlms"] = ($GLOBALS["sqlms"] ?? 0) + $q->time; $f = preg_replace('/\s+/', ' ', substr($q->sql, 0, 160)); $porForma[$f] = ($porForma[$f] ?? 0) + $q->time; });
  if ($tx) DB::beginTransaction();
  $GLOBALS["sqlms"] = 0; $t = microtime(true);
  $resp = $kernel->handle($r);
  $ms = (microtime(true) - $t) * 1000;
  if ($tx) DB::rollBack();

  return [$resp, $n, $ms, $porForma];
}
[$_, $m, $uri] = $argv; $cuerpo = isset($argv[3]) && $argv[3] !== '' ? json_decode($argv[3], true) : null; $pasadas = (int)($argv[4] ?? 1);
if (getenv("TOKEN")) { $tok = getenv("TOKEN"); } else { [$l] = pedir('POST', '/api/login/credentials', ['username'=>'administrador','password'=>'patreongreat'], null, false);
$tok = json_decode($l->getContent(), true)['el_token'] ?? null;
if (!$tok) { echo "login: ".$l->getStatusCode()." ".substr($l->getContent(),0,200)."\n"; exit(1); }
if (getenv("SOLO_LOGIN")) { echo $tok; exit(0); } }
$pf = []; $c = '';
for ($i = 0; $i < $pasadas; $i++) {
  DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
  [$resp, $n, $ms, $pf] = pedir($m, $uri, $cuerpo, $tok);
  $c = $resp->getContent();
  if ($resp->getStatusCode() >= 400) echo substr($c,0,300),"\n"; printf("%s %s  estado=%d consultas=%d ms=%.0f sql_ms=%.0f bytes=%d sha1=%s\n", $m, $uri, $resp->getStatusCode(), $n, $ms, $GLOBALS["sqlms"], strlen($c), substr(sha1($c),0,12));
}
if (getenv('FORMAS')) { arsort($pf); foreach (array_slice($pf, 0, (int)getenv('FORMAS')) as $f => $k) printf("%7.0fms  %s\n", $k, $f); }
if (getenv('GUARDAR')) file_put_contents(getenv('GUARDAR'), $c);
