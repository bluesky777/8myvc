{{-- La maqueta del segundo correo del proyecto, copiada de `emails/reset-password.blade.php`:
     tabla de 600, estilos en línea y nada más. No es gusto — es que los clientes de correo
     no leen hojas de estilo ni entienden flex, y ésta es la única maqueta de este repositorio
     que ya se ha visto llegar bien.

     LO QUE NO SE COPIA: la cabecera de aquél es una imagen servida desde `lalvirtual.edu.co`,
     el dominio de UN colegio, en un correo que mandan los dieciséis. Aquí el encabezado es el
     nombre del colegio en texto; el porqué está en `App\Mail\CompromisoAcademico`.

     Y NADA DE ESTO DICE QUÉ PASÓ: ni asignaturas, ni cuántas perdió, ni si niveló. §4.4 del
     diseño. El contenido se lee entrando, que es además donde se firma. --}}
<table cellpadding="0" cellspacing="0">
	<tr>
		<td class="pattern" width="600">
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td align="left" style="font-family: arial,sans-serif; color: #333; padding-bottom: 4px;">
						<h2 style="margin: 0; font-size: 20px;">{{ $colegio }}</h2>
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; font-size: 16px; color: #1f4e79; padding-bottom: 16px;">
						@if ($momento === \App\Mail\CompromisoAcademico::RESULTADO)
							Resultado del compromiso académico
						@else
							Compromiso académico
						@endif
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; font-size: 14px; line-height: 20px !important; color: #666; padding-bottom: 20px;">
						@if ($momento === \App\Mail\CompromisoAcademico::RESULTADO)
							{{-- El plazo se NOMBRA y no se cuenta: el número de días es del colegio y del
							     año (`config_compromiso.dias_reclamacion`), y va impreso en el papel. Un
							     correo que diga «tienes 5 días» cuando el colegio cambió a 3 la semana
							     pasada es peor que uno que no lo diga. --}}
							Ya está el resultado del compromiso académico de <b>{{ $alumno }}</b>.
							Entra a la plataforma para verlo y dejar constancia de que quedaste enterado.
							<b>Hay un plazo para responder</b>, y empieza a contar hoy: si no estás de
							acuerdo con el resultado, ése es el momento de decirlo.
						@else
							<b>{{ $alumno }}</b> tiene un compromiso académico.
							Entra a la plataforma para leerlo y firmarlo.
						@endif
					</td>
				</tr>
				<tr>
					<td align="left" style="padding-bottom: 20px;">
						<a href="{{ $enlace }}" style="display: inline-block; background: #1f4e79; color: #fff; font-family: arial,sans-serif; font-size: 16px; text-decoration: none; padding: 14px 40px; border-radius: 4px;">Ver el documento</a>
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; font-size: 12px; line-height: 18px; color: #999; padding-bottom: 16px;">
						Si el botón no funciona, copia y pega esta dirección en tu navegador:<br />
						{{ $enlace }}
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; font-size: 12px; line-height: 18px; color: #999;">
						{{-- El correo NO es el canal principal, y la familia tiene que saberlo: el 94 %
						     de los acudientes se entera por la app y por el papel del día de boletines.
						     Esta línea existe para que nadie dé por hecho que sin correo no hay aviso. --}}
						Este mensaje sólo avisa de que el documento está disponible; su contenido se lee
						dentro de la plataforma. Si no lo esperabas, puedes ignorarlo y preguntar en el colegio.
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
