<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

class SondaYearsTest extends CasoDeContrato
{
    public function test_set_actual(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $actuales = fn () => DB::table('years')->where('actual', 1)->orderBy('id')->pluck('id')->all();
        fwrite(STDERR, "\nactuales al empezar: ".json_encode($actuales())."\n");

        $otro = DB::selectOne('SELECT id FROM years WHERE actual = 0 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        fwrite(STDERR, "voy a decir can=false sobre el year {$otro->id}\n");

        $r = $this->withToken($token)->putJson('/api/years/set-actual', ['year_id' => $otro->id, 'can' => false]);
        fwrite(STDERR, '  => '.$r->status().' :: '.$r->getContent()."\n");
        fwrite(STDERR, '  actuales ahora: '.json_encode($actuales())."\n");
        fwrite(STDERR, '  ¿el year '.$otro->id.' quedó actual? '.DB::table('years')->where('id', $otro->id)->value('actual')."\n");

        $this->addToAssertionCount(1);
    }

    public function test_useractive(): void
    {
        $u = $this->usuarioDeTipo('Usuario');
        $token = $this->tokenDe($u->username);

        $sinPeriodos = DB::selectOne('SELECT y.id FROM years y
            LEFT JOIN periodos p ON p.year_id = y.id AND p.deleted_at IS NULL
            WHERE y.deleted_at IS NULL GROUP BY y.id HAVING COUNT(p.id) = 0 LIMIT 1');
        fwrite(STDERR, "\naño sin periodos: ".json_encode($sinPeriodos)."\n");

        $otro = DB::selectOne('SELECT y.id FROM years y INNER JOIN periodos p ON p.year_id=y.id
            WHERE y.deleted_at IS NULL AND y.id <> (SELECT year_id FROM periodos WHERE id=?) LIMIT 1', [$u->periodo_id]);
        $r = $this->withToken($token)->putJson('/api/years/useractive/'.$otro->id, []);
        fwrite(STDERR, 'useractive a otro año => '.$r->status().' :: '.substr($r->getContent(), 0, 200)."\n");
        fwrite(STDERR, '  periodo_id del usuario ahora: '.DB::table('users')->where('id', $u->id)->value('periodo_id')."\n");

        $this->addToAssertionCount(1);
    }

    public function test_papelera(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
        $y = DB::selectOne('SELECT id FROM years WHERE actual = 0 AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');

        $r = $this->withToken($token)->deleteJson('/api/years/delete/'.$y->id);
        fwrite(STDERR, "\ndelete year {$y->id} => ".$r->status().'  deleted_at: '.json_encode(DB::table('years')->where('id', $y->id)->value('deleted_at'))."\n");
        $r = $this->withToken($token)->getJson('/api/years/trashed');
        fwrite(STDERR, 'trashed => '.$r->status().'  cuántos: '.count($r->json())."\n");
        $r = $this->withToken($token)->putJson('/api/years/restore/'.$y->id, []);
        fwrite(STDERR, 'restore => '.$r->status().'  deleted_at: '.json_encode(DB::table('years')->where('id', $y->id)->value('deleted_at'))."\n");

        $this->addToAssertionCount(1);
    }
}
