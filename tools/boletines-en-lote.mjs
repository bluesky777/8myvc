#!/usr/bin/env node
/*
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * BOLETINES DE OTROS COLEGIOS EN LOTE, DESDE UNA CARPETA  *(26 sep 2026)*
 * `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §11, puerta 3: Joseth con Claude Code.
 *
 * El colegio le manda a Joseth las fotos y los PDF. Claude Code los lee y escribe `lote.tsv` con el
 * formato de §11.1 (las mismas columnas que pide el prompt de la pantalla). Este guion hace lo
 * demás contra la API del colegio, con las MISMAS rutas que la pantalla:
 *
 *   1. ENSAYO (por defecto): no escribe nada. Deja junto a la tabla
 *        informe.md          lo que está listo y lo que hay que decidir, con los candidatos
 *        decisiones.json     una plantilla con lo que se puede adivinar ya puesto
 *   2. Se completa `decisiones.json` --a mano o pidiéndoselo a Claude con el informe delante-- y se
 *      vuelve a correr el ensayo hasta que el informe diga «0 por decidir».
 *   3. --crear: escribe, y archiva en cada año el archivo de la columna `archivo` que esté en la
 *      carpeta. Sin «0 por decidir» no escribe: el servidor devuelve 422 y aquí se dice por qué.
 *
 * Uso:
 *   MYVC_CLAVE=… node tools/boletines-en-lote.mjs --api https://colegio.ejemplo.com/api \
 *       --usuario administrador --carpeta ~/Boletines/colegio-x [--crear]
 *
 * La carpeta tiene `lote.tsv` y los archivos. La clave va por variable de entorno y no por
 * argumento: un argumento queda en el historial del shell.
 *
 * Sin dependencias: Node 18 o más nuevo (fetch, FormData y Blob vienen con él).
 * ════════════════════════════════════════════════════════════════════════════════════════════
 */

import { readFile, writeFile, access } from 'node:fs/promises';
import { basename, join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, todos) => {
	if (a.startsWith('--')) { acc.push([a.slice(2), todos[i + 1]?.startsWith('--') || todos[i + 1] === undefined ? true : todos[i + 1]]); }
	return acc;
}, []));

const API = String(args.api ?? '').replace(/\/$/, '');
const USUARIO = args.usuario;
const CLAVE = process.env.MYVC_CLAVE;
const CARPETA = args.carpeta;
const CREAR = args.crear === true;

if (!API || !USUARIO || !CLAVE || !CARPETA) {
	console.error('Falta algo. Uso: MYVC_CLAVE=… node tools/boletines-en-lote.mjs --api URL/api --usuario U --carpeta DIR [--crear]');
	process.exit(2);
}

const TABLA = join(CARPETA, 'lote.tsv');
const DECISIONES = join(CARPETA, 'decisiones.json');
const INFORME = join(CARPETA, 'informe.md');

/* ── leer la tabla: tabuladores, con el encabezado de §11.1 ─────────────────────────────── */

const SINONIMOS = { 'año': 'year', ano: 'year', year: 'year' };

function leerTabla(texto) {
	const lineas = texto.replace(/\r/g, '').split('\n').filter((l) => l.trim() && !/^\s*```/.test(l));
	const partir = lineas[0].includes('\t')
		? (l) => l.split('\t')
		: (l) => l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|');
	const filas = lineas.filter((l) => !/^\s*\|?\s*:?-{2,}/.test(l));
	const campos = partir(filas[0]).map((c) => {
		const n = c.trim().toLowerCase();
		return SINONIMOS[n] ?? n;
	});
	return filas.slice(1).map((l) => Object.fromEntries(partir(l).map((v, i) => [campos[i], v.trim()])));
}

/* ── la API ─────────────────────────────────────────────────────────────────────────────── */

async function pedir(metodo, ruta, token, cuerpo) {
	const r = await fetch(`${API}/${ruta}`, {
		method: metodo,
		headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
		body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
	});
	const texto = await r.text();
	let json = null;
	try { json = JSON.parse(texto); } catch { /* no era JSON */ }
	if (!r.ok) {
		throw new Error(`${metodo} ${ruta} → ${r.status}: ${json?.message ?? json?.error ?? texto.slice(0, 300)}`);
	}
	return json;
}

async function entrar() {
	const r = await pedir('POST', 'auth/login', null, { username: USUARIO, password: CLAVE });
	if (!r?.el_token) { throw new Error('El login no devolvió token.'); }
	return r.el_token;
}

async function archivar(token, anoId, ruta) {
	const bytes = await readFile(ruta);
	const forma = new FormData();
	forma.append('file', new Blob([bytes]), basename(ruta));
	const r = await fetch(`${API}/otros-colegios/${anoId}/documento`, {
		method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body: forma,
	});
	if (!r.ok) { throw new Error(`${r.status} ${(await r.text()).slice(0, 200)}`); }
}

/* ── el informe ─────────────────────────────────────────────────────────────────────────── */

/** Lo que le falta a un grupo, con la misma regla que la pantalla y que el servidor. */
function pendientes(g, d = {}) {
	if (d.accion === 'omitir' || d.accion === 'conservar') { return []; }
	const falta = [];
	if (!g.alumno && !d.alumno_id) { falta.push('alumno_id'); }
	if (!g.escala) { falta.push('escala (corregir la tabla)'); }
	if (g.existente && !d.accion) { falta.push('accion: conservar | reemplazar' + (g.opciones.includes('combinar') ? ' | combinar' : '')); }
	for (const r of g.repetidas) {
		if (d.repetidas?.[r.clave] === undefined) { falta.push(`repetidas["${r.clave}"]`); }
	}
	return falta;
}

function informe(ensayo, decisiones) {
	const l = [];
	const porDecidir = ensayo.grupos.filter((g) => pendientes(g, decisiones[g.clave]).length > 0);
	l.push('# Boletines en lote — informe del ensayo', '');
	l.push(`API: ${API} · ${new Date().toLocaleString('es-CO')}`, '');
	l.push(`**${ensayo.resumen.grupos} boletines · ${ensayo.resumen.filas} notas · ${porDecidir.length} por decidir**`, '');
	if (porDecidir.length === 0) { l.push('Todo decidido: se puede correr con `--crear`.', ''); }

	for (const g of ensayo.grupos) {
		const d = decisiones[g.clave] ?? {};
		const falta = pendientes(g, d);
		l.push(`## ${falta.length ? '⚠︎' : '✓'} ${g.alumno?.nombre ?? g.alumno_texto} · ${g.year}`, '');
		l.push(`- clave: \`${g.clave}\``);
		l.push(`- ${g.colegio ?? 'sin colegio'} · ${g.grado_texto ?? 'sin grado'} · escala ${g.escala ? `${g.escala.min}–${g.escala.max}, aprueba ${g.escala.aprueba}` : 'FALTA'}`);
		if (g.alumno) {
			l.push(`- alumno: ${g.alumno.nombre} (id ${g.alumno.id}, por ${g.alumno.como})`);
		} else {
			l.push(`- alumno SIN CONFIRMAR («${g.alumno_texto}»${g.documento ? `, doc ${g.documento}` : ''}). Candidatos:`);
			for (const c of g.candidatos) { l.push(`  - id ${c.id}: ${c.nombre}${c.documento ? ` · ${c.documento}` : ''} · ${Math.round(c.parecido * 100)} %`); }
			if (!g.candidatos.length) { l.push('  - ninguno: revisar el documento o el nombre en la tabla, o `accion: omitir`'); }
		}
		if (g.cursado_aqui) { l.push(`- ojo: en ${g.year} estaba matriculado aquí; sus notas de ese año ya están en el sistema`); }
		if (g.existente && g.comparacion) {
			l.push(`- YA ESTABA GUARDADO${g.comparacion.iguales ? ' (idéntico)' : ''}:`, '', '| materia | lo que hay | lo que llega | estado |', '|---|---|---|---|');
			for (const c of g.comparacion.encabezado.filter((c) => c.distinto)) { l.push(`| *${c.campo}* | ${c.hay ?? '—'} | ${c.nuevo ?? '—'} | distinto |`); }
			for (const m of g.comparacion.materias) { l.push(`| ${m.asignatura} \`${m.clave}\` | ${m.hay ?? '—'} | ${m.nuevo ?? '—'} | ${m.estado} |`); }
			l.push('');
		}
		for (const r of g.repetidas) {
			l.push(`- «${r.materia}» repetida (\`${r.clave}\`): ${r.opciones.map((o) => `fila ${o.n} = ${o.nota_original}${o.archivo ? ` (${o.archivo})` : ''}`).join(' · ')}`);
		}
		const sinMateria = g.filas.filter((f) => !f.materia && d.materias?.[f.n] === undefined);
		for (const f of sinMateria) {
			l.push(`- materia sin emparejar, fila ${f.n} «${f.materia_texto}»${f.candidatos.length ? `: ¿${f.candidatos.map((c) => `${c.id} ${c.nombre}`).join(' / ')}?` : ''} (opcional: queda con su nombre)`);
		}
		if (falta.length) { l.push(`- **falta en decisiones.json:** ${falta.join(', ')}`); }
		l.push('');
	}
	return { texto: l.join('\n'), porDecidir: porDecidir.length };
}

/** La plantilla de decisiones: lo que ya estaba, más lo que se puede adivinar sin riesgo. */
function plantilla(ensayo, previas) {
	const d = { ...previas };
	for (const g of ensayo.grupos) {
		d[g.clave] ??= {};
		if (!g.existente && !d[g.clave].accion) { d[g.clave].accion = 'crear'; }
		if (g.comparacion?.iguales && !d[g.clave].accion) { d[g.clave].accion = 'conservar'; }
	}
	return d;
}

/* ── main ───────────────────────────────────────────────────────────────────────────────── */

const filas = leerTabla(await readFile(TABLA, 'utf8'));
let decisiones = {};
try { decisiones = JSON.parse(await readFile(DECISIONES, 'utf8')); } catch { /* primera vuelta */ }

const token = await entrar();
const ensayo = await pedir('PUT', 'otros-colegios/lote/ensayo', token, { filas, decisiones });
decisiones = plantilla(ensayo, decisiones);
const { texto, porDecidir } = informe(ensayo, decisiones);

await writeFile(INFORME, texto);
await writeFile(DECISIONES, JSON.stringify(decisiones, null, '\t'));
console.log(`${ensayo.resumen.grupos} boletines, ${ensayo.resumen.filas} notas, ${porDecidir} por decidir. Informe: ${INFORME}`);

if (!CREAR) { process.exit(porDecidir > 0 ? 1 : 0); }

if (porDecidir > 0) {
	console.error('No se crea nada: quedan boletines por decidir (ver el informe).');
	process.exit(1);
}

const r = await pedir('POST', 'otros-colegios/lote', token, { filas, decisiones });
console.log(`Creados ${r.creados}, reemplazados ${r.reemplazados}, combinados ${r.combinados}, conservados ${r.conservados}, omitidos ${r.omitidos}; ${r.notas} notas; ${r.aprendidas} materias aprendidas.`);

/* Los archivos: cada año recibe los de sus filas que estén en la carpeta. */
let archivados = 0;
const faltan = [];
for (const g of ensayo.grupos) {
	const anoId = r.anos?.[g.clave];
	if (!anoId) { continue; }
	for (const nombre of new Set(g.filas.map((f) => f.archivo).filter(Boolean))) {
		const ruta = join(CARPETA, nombre);
		try {
			await access(ruta);
			await archivar(token, anoId, ruta);
			archivados++;
		} catch (e) {
			faltan.push(`${nombre}: ${e.message ?? e}`);
		}
	}
}
console.log(`Archivados ${archivados} documentos.`);
if (faltan.length) { console.error('No se archivaron:\n  ' + faltan.join('\n  ')); }
