<?php

namespace Tests\Contrato;

use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * LEER UN BOLETÍN CON LA IA DE MYVC (26 sep 2026), `IaController::postBoletin`. La puerta 2 de
 * `myvc_front/docs/notas-de-otro-colegio/NOTAS-DE-OTRO-COLEGIO.md` §11.
 *
 * Lo que tiene que sostenerse: el archivo llega al proxy en base64 con su tipo; lo que no es PDF,
 * JPG o PNG no sale de aquí; quien no edita alumnos no lo manda; y la frase del proxy cuando el
 * PDF pasa de 20 páginas (413) llega tal cual.
 */
class BoletinLeidoConIaTest extends CasoDeContrato
{
    private function conProxy(): void
    {
        config(['services.ia.url' => 'https://proxy.test', 'services.ia.secreto' => 'secreto-de-prueba']);
    }

    private function token(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    public function test_la_foto_llega_al_proxy_en_base64_y_vuelven_las_filas(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/boletin/leer' => Http::response([
            'datos' => ['filas' => [['alumno' => 'Ana', 'materia' => 'Matemáticas', 'nota' => '4.1']], 'dudas' => []],
            'quedan' => ['lecturas' => 9],
        ])]);

        $this->withToken($this->token())
            ->post('/api/ia/boletin/leer', ['file' => UploadedFile::fake()->image('boletin.jpg', 40, 60)], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('datos.filas.0.materia', 'Matemáticas')
            ->assertJsonPath('quedan.lecturas', 9);

        Http::assertSent(fn (PeticionHttp $p) => str_ends_with($p->url(), '/boletin/leer')
            && $p['archivo']['tipo'] === 'image/jpeg'
            && base64_decode($p['archivo']['base64'], true) !== false
            && isset($p['usuario']['id']));
    }

    public function test_lo_que_no_es_pdf_jpg_o_png_no_sale(): void
    {
        $this->conProxy();
        Http::fake();

        $this->withToken($this->token())
            ->post('/api/ia/boletin/leer', ['file' => UploadedFile::fake()->create('notas.txt', 3, 'text/plain')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_un_pdf_de_mas_de_veinte_paginas_trae_la_frase_del_proxy(): void
    {
        $this->conProxy();
        Http::fake(['proxy.test/*' => Http::response(['error' => 'El PDF tiene 31 páginas; el tope es 20 por archivo. Divídelo.'], 413)]);

        $this->withToken($this->token())
            ->post('/api/ia/boletin/leer', ['file' => UploadedFile::fake()->image('b.png', 40, 60)], ['Accept' => 'application/json'])
            ->assertStatus(413)->assertJson(['message' => 'El PDF tiene 31 páginas; el tope es 20 por archivo. Divídelo.']);
    }

    public function test_un_alumno_no_lo_manda(): void
    {
        $this->conProxy();
        Http::fake();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Alumno')->username))
            ->post('/api/ia/boletin/leer', ['file' => UploadedFile::fake()->image('b.jpg', 40, 60)], ['Accept' => 'application/json'])
            ->assertStatus(403);
        Http::assertNothingSent();
    }
}
