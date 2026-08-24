# CERT-1 — el consecutivo de los certificados

**Rama:** `fix/consecutivo-de-certificados` · **Sesión:** `8myvc-e0` ·
**Noche del 25 ago 2026** · Es el **punto 1 de la lista de la mañana de Joseth**.

Cierra las cuatro consecuencias que la [05 §195](../05-codigo-muerto-y-roto.md)
midió y la [§225](../05-codigo-muerto-y-roto.md) dejó en rojo. **Menos una: el
permiso, que sigue esperando a Joseth y está abajo escrito como un sí o un no.**

---

## 1. Lo primero, porque cambia lo que se creía: **la red no era una red**

El test que fijaba la carrera —`test_dos_lecturas_a_la_vez_no_pueden_gastar_el_mismo_numero`—
**no podía volverse verde lo arreglara quien lo arreglara.**

No llamaba al endpoint. Ejecutaba el `SELECT` y los dos `UPDATE` **crudos,
copiados a mano desde el controlador**, dentro del propio test:

    $leeA = (int) DB::select($lectura)[0]->…;
    $leeB = (int) DB::select($lectura)[0]->…;
    DB::update(… $leeA + 1 …);  DB::update(… $leeB + 1 …);
    assertSame($partida + 2, $final);

O sea que medía **su propia copia del patrón, no el código de producción**. Poner
`FOR UPDATE` en `BolfinalesController` lo dejaba **exactamente igual de rojo**. Y
copiarle también el `FOR UPDATE` al test tampoco lo salvaba: `CasoDeContrato` usa
`DatabaseTransactions`, o sea **una sola conexión**, y **un `FOR UPDATE` no se
bloquea contra sí mismo**.

Es la regla de la casa en su forma exacta —*el instrumento correcto sobre el
objeto equivocado*— y sólo se ve **preguntando qué objeto mide el test**, no si
el test pasa: el intercalado que ejecutaba era real y el mensaje que imprimía era
cierto.

> **Un rojo que no puede volverse verde no es una red: es un párrafo con
> paréntesis.** Y aquí además habría bloqueado para siempre el paso que convierte
> a estos tests en la red del arreglo — quitarles `#[Group('rojo')]`.

**Aprobado por `8myvc-94` antes de tocarlo.** Lo que hay ahora en su lugar:

| test | qué vigila |
|---|---|
| `test_el_consecutivo_se_lee_bloqueado_y_dentro_de_transaccion` | el **mecanismo**: `DB::listen` sobre una llamada real, la lectura sale con `FOR UPDATE` y en `transactionLevel() > 1` |
| `test_dos_aperturas_seguidas_gastan_dos_numeros_distintos` | la **consecuencia**: ida y vuelta, dos aperturas → dos números |
| `test_el_patron_sin_bloqueo_pierde_un_incremento` | **por qué hace falta**: el intercalado, conservado, afirmando lo que sí demuestra |

### Y el matiz honesto, que va escrito en el propio test

**El primero afirma sobre el MECANISMO, no sobre el resultado.** El resultado que
de verdad importa —*«dos peticiones simultáneas no repiten número»*— **no es
observable en esta suite**, porque una sola conexión no puede demostrar exclusión
mutua. Lo que garantiza es que el bloqueo **está pedido y en el sitio correcto**;
que MySQL lo respeta es cosa de MySQL.

Se dice así a propósito: **un guardián que promete más de lo que puede ver** es
justo lo que este fichero acaba de quitar de en medio.

---

## 2. La carrera: transacción y `FOR UPDATE`

`Informes/BolfinalesController::detailedNotasGrupo()`. El patrón **se copió de
`DefinitivasPeriodosController::putUpdate`** —lo que dejó puesto la fase 3 de las
definitivas— y no se inventó otro:

    DB::transaction(function () {
        $contador = DB::select('SELECT id, contador_certificados FROM years
            WHERE deleted_at is null and actual=1 ORDER BY id LIMIT 1 FOR UPDATE')[0];
        DB::update('UPDATE years SET contador_certificados=? WHERE id=?', […]);
    });

**Sigue siendo `DB::select(…)[0]` y no `selectOne`** a propósito: si no hubiera
year `actual=1`, esto falla igual que antes. Cambiarlo a un null-check haría que
el endpoint contestara 200 sin subir el contador, que es **una conducta nueva**, y
arreglar una carrera no es el sitio donde estrenarla.

---

## 3. Los dos endpoints sin validación — **son dos, y el segundo no lo había nombrado nadie**

`PUT bolfinales/cambiar-contador-certificados` y **`PUT bolfinales/cambiar-contador-folios`**
(`routes/api/academico.php:158-159`). La lista de la mañana habla sólo del
primero; el segundo es **la misma línea sobre otra columna**, con el mismo guard y
la misma ausencia de validación.

Los dos pasan ahora por `consecutivoValidado()`, que **aborta antes de escribir**:
**422**, no 400 —código correcto en código nuevo, aunque el legacy de al lado dé
400 para todo—, y la comprobación es `^\d+$`.

Van como **dos tests y no como un `dataProvider`**: si mañana se toca uno solo,
esto tiene que decir **cuál falta**.

### La validación que casi rompe la pantalla buena, y cómo se cazó

La primera versión usaba `FILTER_VALIDATE_INT`, que es lo que uno escribe sin
pensar. **`filter_var('007', FILTER_VALIDATE_INT)` es `false`.**

Y `'007'` **es exactamente lo que manda la pantalla**:
`certificadoEstudioDir.html` es un `<input ng-model="year.contador_certificados">`
**sin `type="number"`**, o sea que AngularJS manda **la cadena tal cual la trajo el
backend**.

Y el relleno está ahí. Medido en `simonbolivar_testing_e0`, **7 de los 8 years
vivos** llevan ceros a la izquierda —`007`, `021`, `022`, `037`, `044`, `045`,
`060`— y **el octavo es el actual (`115`), que sólo se libra por haber pasado de
tres dígitos**. `007` es literalmente el year `id=1`. Esa validación habría
contestado **422 a la pantalla que hoy funciona** en todos los colegios con
relleno — y el actual de cualquier colegio joven todavía lo lleva.

> **Una validación que rechaza el caso que venía a proteger es peor que no
> tenerla**, porque además parece que funciona: el test del cuerpo inválido sigue
> verde y el daño sólo se ve desde la pantalla. Salió de preguntar *«¿qué manda
> el cliente de verdad?»* en vez de *«¿qué es un entero?»* — la misma pregunta que
> decidió el §4, hecha del otro lado.

Por eso la comprobación es **`^\d+$`**, que dice las tres cosas a la vez y sin ese
efecto: **dígitos**, **entero** y **no negativo** (`'-1'` no casa, y un negativo no
existe en un talonario). De paso rechaza lo que `is_numeric` habría dejado pasar
—`'1.5'`, `'1e3'`—, que tampoco son consecutivos de papel oficial. Se admite **0**
y `'000'`: es como arranca un colegio nuevo, y `YearsController` lo copia de un
year al siguiente.

**Y devuelve la cadena, no el entero.** El relleno **es la convención**: devolver un
`int` convertiría `'007'` en `'7'` **en un sitio donde hoy no pasa**, y eso es
cambiar el número impreso — ver §6. Lo fija
`test_el_consecutivo_relleno_de_ceros_sigue_entrando`, que comprueba las dos
mitades: que entra, y que **el relleno se conserva**.

---

## 4. La cadena `"false"` quemaba un folio — y entra **por asimetría**, no por la medición

`if (Request::input('aumentar_contador') == true)` con `==`. En PHP **cualquier
cadena no vacía que no sea `'0'` es cierta**, así que `"false"` —lo que manda un
cliente que cree estar diciendo «no subas»— entraba y **gastaba un número**.

Es la misma comparación laxa que ya se corrigió **doce líneas más arriba en el
mismo fichero** (`year_selected`, donde `0 == 'true'` era cierto). *Se arregló la
de al lado porque dio un síntoma visible, y ésta sólo gastaba un número que nadie
echa en falta.*

### Por qué entró sin esperar decisión

Se midió en los cuatro clientes: `aumentar_contador` **sólo lo manda `myvc_front`**
—9 sitios en fuente, 1 en `myvc_dist`— y **siempre como booleano `true`** (literal
`true`, y `!0` en el minificado). **`myvc_front_2` y `myvc_flutter`: cero sitios.**

**Pero eso no es lo que lo desbloquea**, y conviene que quede escrito: esa medición
**no ve las copias de `myvc_front` desplegadas en los dieciséis colegios**, que
pueden ir a versiones distintas. Lo que lo desbloquea —criterio de `8myvc-94`— es
que **el cambio es estrictamente asimétrico hacia el lado seguro**:

| | quema |
|---|---|
| antes (`== true`) | cualquier cadena no vacía distinta de `"0"` |
| ahora (`FILTER_VALIDATE_BOOLEAN`) | `true`, `1`, `"1"`, `"true"`, `"yes"`, `"on"` |

**Todo lo que deja de quemar dejaba de deber quemarse, y no hay ni un solo valor
que hoy no queme y mañana sí.** En una cuenta de papel oficial la dirección
irreversible es quemar: **un folio no quemado se quema después; uno quemado no
vuelve.** Un cambio que sólo puede mover el resultado hacia el lado recuperable no
necesita la decisión de Joseth — sólo necesita que alguien haya comprobado que es
asimétrico.

**No sustituye la cura del front, la respalda.** La §225 sigue teniendo razón:
`myvc_front` tiene que **OMITIR la clave**, no mandar `false`. `8myvc-94` se lo
pasa a la coordinación del front.

El guardián es la tabla, no el `filter_var`:
`test_que_valores_queman_un_folio_y_cuales_no` enumera **trece valores, seis que
queman y siete que no**, y afirma **en las dos direcciones** — si alguien lo
«simplifica» de vuelta cae por arriba, y si alguien lo endurece de más y el
certificado deja de gastar número, cae por abajo.

---

## 5. **Lo que NO entra y es de Joseth: el permiso.** El cambio exacto, para que sea un sí o un no

Hoy los dos endpoints llevan `auth.personal`, o sea que **cualquiera del personal
docente puede fijar el consecutivo de certificados del colegio**. Restringirlo
**le quita una pantalla a alguien que hoy la usa**, y ahí **no hay asimetría** —a
diferencia del §4—, así que la decisión es suya.

**No hace falta un guard nuevo ni un permiso nuevo.** El criterio de secretaría ya
existe, con dueño y documentado: `Autoriza::esAdministrativo()` —superusuario o
rol `Secretario`—, que es exactamente *«la secretaria administra la estructura del
colegio»*, y el rol lo creó `2026_08_21_100000_create_rol_secretario` **sin
dárselo a nadie**.

**El cambio es una línea**, como primera línea de `consecutivoValidado()` en
`app/Http/Controllers/Informes/BolfinalesController.php` — y cubre **los dos
endpoints a la vez**, porque los dos pasan por ahí:

```php
Autoriza::esAdministrativo($this->user) || abort(403, 'Fijar el consecutivo del colegio es de secretaría.');
```

(más el `use App\Support\Autoriza;` de la cabecera).

**Si la respuesta es sí**, se añade también su test —la pareja de siempre: un
`Secretario` sin `is_superuser` entra, un `Profesor` corriente recibe **403** y
**la columna no se mueve**—, porque las dos mitades se cumplen por separado.

**Si la respuesta es no**, esto se queda como está y **la validación del §3 sigue
valiendo**: hoy cualquiera del personal puede fijar el número, pero ya no puede
dejar ahí `'no soy un número'`.

> **Cuidado con la tentación de ampliarlo de paso.** La regla de la casa es que
> **crear un rol no regala permisos**: si el sí llega, se ancla a
> `esAdministrativo` y no se aprovecha para mover ninguna otra de sus llamadas.

---

## 6. Lo que queda escrito y **no se hizo**, con su motivo

1. **El relleno de ceros.** `(int)'007' + 1` es `8` sobre una columna `VARCHAR`,
   así que queda `'8'` y **se pierde el padding** ([§195.3](../05-codigo-muerto-y-roto.md)).
   **No entra: es formato del número impreso, no corrección de un fallo** — y el
   `(int)` es correcto y necesario (sin él, `'' + 1` lanza `TypeError` en PHP 8).
   Cambiarlo movería lo que sale en el papel en los colegios que ya están a tres
   dígitos. Es una decisión de colegio, pequeña y de una línea
   (`str_pad`), pero decisión.
2. **Cuántos números se han quemado ya.** No se puede saber: **no hay ninguna
   tabla donde quede constancia de un certificado emitido** —`config_certificados`
   es maquetación— y `putCambiarContadorCertificados` **no escribe en
   `bitacoras`**. La resta *contador − emitidos* no tiene minuendo. Queda como
   candidato natural para **AUD-4**.
3. **El permiso** — §5.

---

## 7. Y una herramienta compartida que hay que tocar: **su control positivo nombra el sitio equivocado**

`tools/verdad-laxa-que-escribe.py` lleva escrito en la cabecera:

> *Tiene que encontrar `Informes/BolfinalesController:85-86` —el contador de
> certificados—. **Si no sale ahí, el detector está roto y su lista no vale.***

**Medido sobre `main`, sin mi arreglo:** el `if` del contador está en las líneas
**156-157**. Las líneas **85-86 de `main` son el docblock y la firma de
`periodosDelAnio()`** — nada que ver con el contador.

**El detector funciona**: encuentra el sitio (línea 156) antes y después. Lo que
está mal es **la cita de su control**, y eso es peor de lo que parece, porque el
control está redactado como *«si no sale ahí, no vale»* — o sea que **la única
instrucción que da para desconfiar de él apunta a un sitio que no es**.

Población, con las dos corridas hechas el mismo día y sobre los mismos 112
ficheros:

| árbol | `if` mirados | cumplen las tres condiciones |
|---|---|---|
| `main` (sin el arreglo) | 980 | **21** |
| `e0` (con el arreglo) | 983 | **20** |

El −1 es exactamente el `== true` de esta § y el +3 son los tres `if` de
`consecutivoValidado()`, ninguno laxo. **La fila que sigue saliendo en
`BolfinalesController` es el `Request::has(…)` de fuera, que es inofensivo** — el
detector no puede distinguirlo, y eso es lo normal: *un detector da sitios donde
mirar, nunca una lista de fallos*.

**No lo toqué**: `tools/` es compartido y un fichero tiene un dueño. Avisado a
`8myvc-94`, que **lo arregló en `47f206a`**: ahora se cita **por el nombre del
método** (`detailedNotasGrupo`) y no por unas líneas, con la población de arriba
dentro. *Un número de línea envejece con cada commit del fichero que cita; un
nombre de método también puede morir, pero muere ruidosamente — no lo
encuentras.*

---

## 8. Los dos controles: **la red se probó al revés, dos veces**

Un test verde no dice nada hasta que se sabe **qué le hace caer**. Los dos
controles se corrieron en `simonbolivar_testing_e0` (94 tablas, 2.351 usuarios).

### Control 1 — revertir el arreglo: **caen 4 de 7**

Con el controlador restaurado a `main` y **los tests nuevos tal cual**:

| test | ¿cae? |
|---|---|
| `..._se_lee_bloqueado_y_dentro_de_transaccion` | **cae** |
| `..._cambiar_contador_certificados_...` | **cae** |
| `..._cambiar_contador_folios_...` | **cae** |
| `..._que_valores_queman_un_folio_y_cuales_no` | **cae** |
| `..._dos_aperturas_seguidas_gastan_dos_numeros_distintos` | no cae |
| `..._el_patron_sin_bloqueo_pierde_un_incremento` | no cae |
| `..._el_consecutivo_relleno_de_ceros_sigue_entrando` | no cae |

**Los tres que no caen es correcto que no caigan, y cada uno por su motivo** —lo
importante es que ninguno de los tres pretende vigilar el arreglo: el de las dos
aperturas ya dice en su docblock que no demuestra exclusión mutua; el del
intercalado ejecuta su propia copia a propósito; y el del relleno vigila **la
validación nueva**, que antes no existía, así que `'007'` entraba igual.

### Control 2 — el que de verdad importaba: **poner la validación mala**

Con `FILTER_VALIDATE_INT` en lugar de `^\d+$` y todo lo demás igual:

    1 failed, 6 passed
    ⨯ el consecutivo relleno de ceros sigue entrando

**Cae exactamente uno, y los otros seis se quedan verdes tapándolo.** Eso es la
demostración ejecutable de lo que el §3 afirmaba en prosa: los cinco tests de
validación y carrera **pasan encantados** con un endpoint que ha dejado de aceptar
el número que manda la pantalla. *Una validación que rechaza el caso bueno es peor
que no tenerla, porque parece que funciona* — y ahora eso está **medido, no
argumentado**.

---

## 9. Cómo queda

- **7 tests, 66 assertions**, en `tests/Contrato/ConsecutivoDeCertificadosTest.php`,
  **sin `#[Group('rojo')]`**: pasaron a la suite, que es lo que los convierte en la
  red del arreglo y no en una queja archivada.
- **`--testsuite=Contrato` entero en verde: 1.335 tests, 9.944 assertions**, 597 s,
  en `simonbolivar_testing_e0` (94 tablas, 2.351 usuarios).
- **Pint**: una indentación de array en el test. `BolfinalesController` no entra en
  su alcance —Pint sólo pasa por lo que escribió la migración— y por eso no se
  reformatea aquí.
- **Larastan nivel 7 sobre los dos ficheros tocados: `[OK] No errors`.** La suite
  entera da **un** error, en `tests/Contrato/BoletinFortalezaDebilidadTest.php:104`,
  que **no es de este lote y ya está arreglado en `main`**: esta rama parte de
  `5912997`, anterior a ese arreglo. No se toca — se va solo al fundir.
- `tests/Barrido/QuemaDelConsecutivoTest.php` se queda en `barrido`: **mide e
  imprime**, y su pregunta —qué dispara el incremento— ya está contestada arriba.
- **Nada cambia para un cliente en la ruta feliz.** Cuerpos, campos y rutas,
  intactos. Lo que cambia: los dos `cambiar-contador-*` contestan **422** en vez
  de 200 ante un cuerpo que no es un entero, y `aumentar_contador` con una cadena
  que no significa «sí» **ya no quema un folio**.
