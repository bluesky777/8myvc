# Tests de contrato — cómo se usan

La red de seguridad de la migración. No comprueban que el código esté bien:
comprueban que **la respuesta no ha cambiado**. Su único trabajo es gritar
cuando la migración altera algo que el frontend está leyendo.

## Correrlos

```bash
# Una vez, para construir la base de tests
tools/construir-bd-test.sh

# Cada vez
docker exec 8myvc-app-1 php artisan test
docker exec 8myvc-app-1 php artisan test --testsuite=Contrato   # solo los de contrato
```

La base **no** se reconstruye entre tests: cada test corre dentro de una
transacción que se deshace al terminar. Solo hace falta reconstruirla si cambia
el esquema o el seed.

> **Si alguna vez ejecutas `php artisan config:cache` en local, bórralo antes de
> correr los tests** (`php artisan config:clear`). El config cacheado congela el
> `.env`, así que `phpunit.xml` deja de poder apuntar a la base de tests. No hay
> daño —`CasoDeContrato` aborta al ver que la base no acaba en `_testing`— pero
> el mensaje despista. Con `route:cache` no pasa: la suite pasa entera.

## Las tres piezas

| Fichero | Qué es |
|---|---|
| `database/schema/mysql-schema.sql` | El esquema real congelado. 90 tablas. |
| `database/dumps/test-seed.sql` | Los datos: rebanada anonimizada, 46 tablas, ~15.800 filas. |
| `tests/Contrato/Snapshots/*.json` | La forma esperada de cada respuesta. |

Contraseña de todos los usuarios del seed: `test-1234`.

## Cómo funcionan los snapshots

Se guarda la **forma** de la respuesta —qué claves trae y de qué tipo es cada
valor—, no los valores.

```json
{ "el_token": "string", "cambia_anio": "int" }
```

Guardar los valores haría fallar el test porque avanzó un id o cambió una fecha.
Lo que el frontend consume es la forma, y es lo que no puede cambiar.

**La primera vez que un test corre, su snapshot no existe y se crea solo.**
El test avisa por STDERR. Un snapshot recién creado no ha verificado nada:
solo ha registrado el comportamiento de hoy. Hay que leerla antes de fiarse.

**Cuando un test falla porque la forma cambió**, hay dos casos:

1. *No era intencionado* → es una regresión. Ese es el trabajo del test.
2. *Sí lo era* → borra el `.json` y vuelve a correr. El diff del fichero
   regenerado es lo que hay que enseñar a quien lleve `myvc_front`.

## Regenerar el seed

Se genera desde la base real, así que solo se puede hacer en local:

```bash
docker exec 8myvc-app-1 php tools/generar-seed-test.php [year_id] [grupo_id]
```

Por defecto se ancla en el año 8 (2025) y el grupo 98 (Cuarto, 68 alumnos).
Sigue el grafo de claves foráneas hacia fuera, así que lo que entra tiene sus
referencias dentro.

### El detector de fugas

El generador **aborta sin escribir nada** si encuentra un nombre real en el
fichero que iba a generar. Compara palabra a palabra contra los nombres y
apellidos de la base.

No es decorativo. La lista de columnas a anonimizar se quedó corta tres veces:

- `vt_candidatos.plancha` guardaba `PAULINA - SOFIA - EMELY` — forman el nombre
  del equipo con los nombres de pila de las candidatas.
- `calendario.title` lleva `Cumpleaños de <alumno>`.
- `images.nombre` lleva el nombre del alumno en el nombre del fichero.

Si al añadir una tabla el detector se queja, hay dos salidas: añadir la columna
a `$ANONIMAS`, o la tabla entera a `$OMITIDAS`. Silenciarlo con
`COLISIONES_ACEPTADAS` **solo** después de mirar el texto de origen y confirmar
que es una palabra corriente que casualmente es el nombre de alguien.

### Lo que no entra nunca

Expedientes disciplinarios (`dis_procesos`, `dis_libro_rojo`) y PIAR —planes de
apoyo por discapacidad—. Son el dato más sensible del sistema, van en texto
libre, y ningún test los necesita. No se anonimizan: se omiten.

## Añadir un test

```php
class MiTest extends CasoDeContrato
{
    public function test_lo_que_sea(): void
    {
        $usuario = $this->usuarioDeTipo('Profesor');
        $token   = $this->tokenDe($usuario->username);

        $r = $this->getJson('/api/loquesea', ['Authorization' => 'Bearer ' . $token]);

        $r->assertStatus(200);
        $this->compararConInstantanea('loquesea-profesor', $this->forma($r->json()));
    }
}
```

`usuarioDeTipo()` no devuelve "el primer usuario del tipo": devuelve uno cuyo
contexto el seed pueda resolver de verdad. Un Alumno necesita ficha,
matrícula en estado MATR/ASIS/PREM, grupo, y que su periodo sea del mismo año
que el grupo. Sin las cuatro cosas, el endpoint responde 400.

## Cosas que aparecieron en los datos reales

Encontradas construyendo esto. Ninguna está arreglada.

- **Dos años con `actual = 1`** a la vez (2025 y 2026). `LoginController` hace
  `SELECT ... WHERE actual=1` y coge `[0]`, así que hoy funciona por accidente
  de orden.
- **El acudiente 505 tiene una frase de boletín de 252 caracteres en el campo
  `apellidos`.**
- **La tabla `failed_jobs` no existe** en la base real, aunque su migración
  estaba en el repo. Nunca se ejecutó.
