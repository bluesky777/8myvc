# LOGIN-VER — `version_minima_app` en la respuesta del login

> **Lote de la noche del 25 ago 2026, sesión `8myvc-9a`.** Rama
> `feat/version-minima-en-login`, árbol `.worktrees/9a`, base
> `simonbolivar_testing_9a`.
>
> **Lo que entrega:** que el backend mande el número con el que `myvc_flutter`
> decide si se bloquea. La app **ya tiene escrito y probado** el otro lado; esto
> es la mitad que faltaba, y **no enciende nada por sí sola**.

---

## 0. Esto no bloquea a nadie hasta que alguien escriba un número

**Sale del `.env` y por defecto no está.** Sin valor configurado el campo **no
viaja** —la clave no existe en la respuesta, no viaja como `null`— y la forma de
las cuatro respuestas es exactamente la de antes. Los snapshots de contrato que
ya había siguen valiendo sin tocarlos, que es la comprobación de que nada cambió.

Ese matiz —**ausente**, no `null`— es la mitad del diseño y lleva su propio caso:
un `version_minima_app: null` **sí** sería un cambio de forma, y hay clientes que
distinguen «no está» de «está y vale null».

**El día que un colegio ponga el número, empieza a bloquear.** Eso lo decide quien
despliega, no este commit.

---

## 1. El contrato, tal como lo escribió la app

No se reinventa aquí, y por eso se copia literal:

| | |
|---|---|
| Nombre del campo | **`version_minima_app`** |
| Valor | el **`versionCode`** (el `+N` de `pubspec.yaml`), **no** la versión con puntos: `1.4.2+37` es **37** |
| Cómo lo lee la app | tolerante — `"12"` como cadena también le vale |
| Dónde viaja | en la respuesta del login, y por tanto en la del refresco |

**Se manda como entero.** La app acepta las dos formas, así que se elige la que no
admite dos lecturas — y además todo lo que sale de un `.env` es una cadena, o sea
que sin convertirlo aquí el contrato dependería de que lo convirtiera el cliente.

> **El plazo, que es lo único de este lote que caduca.** Si se prefiere otro
> nombre para el campo, hay que decirlo **antes de que se publique una versión de
> la app leyendo éste**. Después, cambiarlo obliga a mandar **los dos** durante
> toda la transición, porque `myvc_flutter` es **una sola app para los dieciséis
> colegios** y no se actualiza a todos a la vez.

---

## 2. La trampa numérica, y por qué queda escrita en el `.env.example`

Medido por `myvc-flutter-8b` y comprobado contra este repositorio:

> **La app publicada es el `versionCode` 1 — y de hecho nunca se ha subido a
> Play**: `pubspec.yaml` dice `1.0.0+1`.

O sea que **hoy cualquier número mayor que 1 deja al colegio entero fuera y sin
salida**. También un 5 o un 10 puestos «por si acaso», y también el 12 que sería
el ejemplo natural. La pantalla de bloqueo manda a Play, y en Play no hay nada a
lo que actualizar.

Lo que eso obliga a escribir, y dónde:

1. **El comentario del `.env.example` no lleva un número de ejemplo suelto.** Dice
   que el valor es el `versionCode` de una versión **que exista en la tienda**, y
   dice qué pasa si se pasa. **El único ejemplo seguro hoy es `1`**, y es el que
   está puesto: un ejemplo con `12` es una invitación a romper un colegio.
2. **La ceremonia va en el `.env.example` y no sólo en la app.** Está anotada en el
   `docs/backend-pendiente.md §4` de `myvc_flutter`, pero **quien rellena el `.env`
   lee éste, no aquél**. Se sube una vez por retirada, con la misma ceremonia que
   un despliegue, y **nunca se copia de un colegio a otro sin mirar**.

Y la única salida que existe hoy si alguien se pasa de número: la pantalla de
bloqueo ofrece «¿Tienes cuenta en otro colegio?», y cerrar sesión olvida el
número. **Al que sólo tiene cuenta en el colegio del dedazo no le sirve de nada**:
su única salida es que alguien toque el servidor.

---

## 3. Dónde se puso, y por qué en cuatro sitios y no en uno

Aquí está la decisión de este lote, y no la trajo la ficha: la trajo mirar **qué
llama la app de verdad**.

La ficha dice «en la respuesta de `/login` (y por tanto en la del refresco, que es
la misma forma)». Leído desde el backend, eso suena a `POST /api/auth/login` y
`POST /api/auth/refresh`, que son las que comparten forma. **Pero la app no llama
a ésas.** Está leído en su código y anotado en
[07-sesion.md](../07-sesion.md#loginredentials-y-post-apilogin-no-se-retiran):

| Cliente | Qué llama | Dónde |
|---|---|---|
| `myvc_flutter` | `POST /login/credentials` y `POST /login` | `lib/Http/Server.dart:36` y `:43` |
| `myvc_front_2` (PIAR) | `POST /login` | `profile.service.ts:17` |
| `myvc_front` | `POST /api/auth/*` | la Fase 3 |

**Poner el campo sólo en `auth/*` habría dejado la función entera sin efecto, con
todos los tests en verde y sin que nadie se enterara** — el bloqueo no se
dispararía nunca porque el número no llegaría nunca por el camino que la app usa.
Es la forma de fallo que este repositorio lleva catalogada: el instrumento
correcto sobre el objeto equivocado.

Así que va en **las cuatro**:

| Respuesta | Dónde se añade | Por qué |
|---|---|---|
| `POST auth/login` | `Sesion::abrir()` | la del front nuevo |
| `POST auth/refresh` | `Sesion::rotar()` | **el único punto donde un mínimo nuevo llega sin que el usuario salga y vuelva** |
| `POST login/credentials` | `LoginController::postCredentials()` | **la que llama la app** |
| `POST login` | `LoginController::postIndex()` | la segunda de la app, y la única del PIAR |

Las dos de `Sesion` salen de una sola línea porque `abrir()` y `rotar()` devuelven
**exactamente la misma forma**; ahí el campo se añade donde se arma la respuesta y
no en el controlador.

> **`POST login` es el sitio menos obvio de los cuatro y se dice**: esa ruta
> devuelve el **contexto del usuario**, no un token, así que no es «una respuesta
> de login» en sentido estricto. Va igual porque es el endpoint que se llama
> `/login`, la app lo lee, y el coste de ponerlo de más es **una clave que nadie
> mira** mientras que el de ponerlo de menos es que el bloqueo no se entere nunca.
> **Cuál de los tres engancha la app está preguntado en el parte**; si sobra
> alguno, quitarlo es una línea.

### Y por qué el refresco no es un extra

El refresco vive **catorce días y rota en cada uso**, así que quien entra a diario
puede pasarse **meses** sin teclear la contraseña. Si el campo sólo viajara en el
login, a ese usuario un mínimo nuevo no le llegaría en todo ese tiempo — que es
justo el usuario al que se quiere alcanzar. Tiene su propio caso, y el caso hace
el viaje entero: se entra **sin** mínimo configurado, se pone el número con la
sesión ya abierta, y se comprueba que el refresco lo trae.

---

## 4. Un valor que no es un número: ni bloquea, ni se calla

`.env` es texto y no lo valida nadie, así que `APP_MOVIL_VERSION_MINIMA=v1.4` o un
`12 # el de agosto` son errores plausibles. Las dos mitades de la respuesta
importan y las dos tienen test:

- **No bloquea.** El campo no se manda. Se falla hacia el lado que no deja a nadie
  fuera, porque el daño de bloquear de más es un colegio entero sin app y sin
  salida.
- **No se calla.** Va al log con el valor dentro.

La segunda es la que cuesta pensar y la que no se puede omitir: **desde el cliente
no se distingue un `.env` mal puesto de un colegio que no exige nada**, y son
dieciséis `.env` distintos. Sin el aviso, el que lo escribió mal cree que está
exigiendo una versión y no exige ninguna — durante meses y sin ningún síntoma.

> Y por qué no vale un `(int)` a secas, que es lo que sale solo: `(int) 'v1.4'` es
> `0` y `(int) 'abc'` es `0`, que la app leería como «sin mínimo». **Da la
> casualidad de que el resultado es correcto, pero por accidente** — y un `12 # el
> de agosto` daría `12`, que es correcto por accidente en la otra dirección y
> silenciosamente distinto de lo que alguien quiso escribir.

---

## 5. Lo que cambia para un cliente — y hay que decirlo

**Una clave nueva y opcional en cuatro respuestas: `version_minima_app`, entera.**

- **Con el `.env` como se despliega (vacío), no cambia nada**: la clave no viaja y
  la forma de las cuatro respuestas es idéntica a la de antes.
- **Ningún cuerpo pierde nada, ningún campo se renombra, ninguna ruta se mueve.**
  Las 542 siguen siendo 542, y no hay migración.
- Los cuatro clientes que leen esas respuestas ignoran una clave que no conocen;
  el único que la va a mirar es `myvc_flutter`, que ya la espera.

**No se ha puesto ningún número en ningún `.env` real y no se ha desplegado nada.**

---

## 6. Lo que no entra

- **No se toca `pubspec.yaml` ni nada de la app**: este lote es el lado del
  backend.
- **No se inventa una ruta nueva.** El campo viaja en respuestas que ya existen, y
  eso es justo por lo que se eligió así: una app que ya está publicada no puede
  aprender a llamar a un endpoint nuevo.
- **No se valida que el número corresponda a una versión real de la tienda.** El
  backend no sabe qué hay en Play, y fingir que lo sabe sería peor que no
  comprobarlo. Lo único que se puede hacer desde aquí es que el `.env.example` lo
  diga con todas las letras, y lo dice.

---

## 7. Un detalle de despliegue que sólo muerde después

**El valor se lee con `config()`, nunca con `env()`.** La única llamada a `env()`
está dentro de `config/aplicacion-movil.php`, que es donde Laravel la resuelve.

No es estilo. Con `php artisan config:cache` puesto —lo razonable en producción, y
algo que este repositorio ya se ha encontrado— **`env()` fuera de `config/`
devuelve `null` siempre**. Un `env('APP_MOVIL_VERSION_MINIMA')` en el ayudante
habría funcionado en local, habría funcionado al desplegar, y habría dejado de
mandar el campo **el día que alguien cacheara la configuración** — sin tocar nada,
sin ningún error, y con el colegio creyendo que sigue exigiendo una versión.

Es la misma familia que el resto del lote: **desde el cliente no se distingue un
mínimo que no se manda de un colegio que no exige ninguno.**
