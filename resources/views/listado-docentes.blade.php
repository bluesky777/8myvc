<table >
    <thead>
        <tr>
            <th style="background-color: #fff7ad; vertical-align: middle">No</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Titularía</th>
            <th style="background-color: #fff7ad; vertical-align: middle; text-align: center">Docente</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Sexo</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Título</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Tipo doc</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Documento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Ciudad Docu</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Fecha nac</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Ciudad nac</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Usuario</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Estado civil</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Barrio</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Dirección</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Teléfono</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Celular</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Email</th>
            
        </tr>
    </thead>
    <tbody>
        @foreach($profesores as $key=>$profe)
        <tr>
            <td>{{++$key}}</td>
            <td style="font-weight: bold; text-align: center">{{$profe->grupos}}</td>
            <td>{{$profe->nombres}} {{$profe->apellidos}}</td>
            <td style="text-align: center">{{$profe->sexo}}</td>
            <td>{{$profe->titulo}}</td>
            <td>{{$profe->abrev}}</td>
            <td>{{$profe->num_doc}}</td>
            <td>{{$profe->ciudad_doc_nombre}}</td>
            <td>{{$profe->fecha_nac}}</td>
            <td>{{$profe->ciudad_nac_nombre}}</td>
            <td>{{$profe->username}}</td>
            <td>{{$profe->estado_civil}}</td>
            <td>{{$profe->barrio}}</td>
            <td>{{$profe->direccion}}</td>
            <td>{{$profe->telefono}}</td>
            <td>{{$profe->celular}}</td>
            <td>{{$profe->email}}</td>
        </tr>
        @endforeach
    </tbody>
</table>