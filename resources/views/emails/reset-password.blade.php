{{-- Mismo diseño que el HTML que estaba incrustado en LoginController::postVerPass.
     Único cambio visible: el botón era una imagen de placehold.it, servicio que
     ya no existe, así que se veía roto. Ahora es un enlace con estilo. --}}
<style>
	/* Shrink Wrap Layout Pattern CSS */
	@media only screen and (max-width: 599px) {
		td[class="hero"] img {
			width: 100%;
			height: auto !important;
		}
		td[class="pattern"] td{
			width: 100%;
		}
	}
</style>

<table cellpadding="0" cellspacing="0">
	<tr>
		<td class="pattern" width="600">
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td class="hero">
						<img src="https://lalvirtual.edu.co/up/images/Logo_MyVc_Header.gif" alt="Mi Colegio Virtual" style="display: block; border: 0;" />
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; color: #333;">
						<h1>My Virtual College</h1>
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; font-size: 14px; line-height: 20px !important; color: #666; padding-bottom: 20px;">
						Has solicitado resetear tu contraseña. Si es así, presiona el botón de abajo. De lo contrario, puedes ignorar este mensaje. Este link sólo será válido durante una hora. Tu usuario es <b>{{ $username }}</b>
					</td>
				</tr>
				<tr>
					<td align="left" style="padding-bottom: 20px;">
						<a href="{{ $enlace }}" style="display: inline-block; background: #333; color: #fff; font-family: arial,sans-serif; font-size: 16px; text-decoration: none; padding: 14px 40px; border-radius: 4px;">Resetear</a>
					</td>
				</tr>
				<tr>
					<td align="left" style="font-family: arial,sans-serif; font-size: 12px; line-height: 18px; color: #999;">
						Si el botón no funciona, copia y pega esta dirección en tu navegador:<br />
						{{ $enlace }}
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
