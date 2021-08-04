<table >
    <thead>
        <tr>
            <th style="background-color: #fff7ad; vertical-align: middle">No</th>
            <th style="background-color: #fff7ad; vertical-align: middle">ID</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Tipo de Documento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Nro de documento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Lugar de Expedición Departamento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Lugar de Expedición Ciudad</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Primer apellido</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Segundo apellido</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Primer nombre</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Segundo nombre</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Usuario</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Estado Matrícula</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Número Matrícula</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Dirección residencia</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Barrio</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Ciudad residencia</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Urbana</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Teléfono</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Celular</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Estrato</th>
            <th style="background-color: #fff7ad; vertical-align: middle">SISBEN</th>
            <th style="background-color: #fff7ad; vertical-align: middle">SISBEN 3</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Fecha de nacim</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Departam nacimiento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Ciudad nacimiento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Sexo</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Nuevo</th>
            <th style="background-color: #fff7ad; vertical-align: middle">RH</th>
            <th style="background-color: #fff7ad; vertical-align: middle">EPS</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Religión</th>
            
            <th style="background-color: #e0f1ff; vertical-align: middle">ID Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Nombres Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Apellidos Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Sexo Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Tipo docu Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">¿Es el acudiente? Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Parentesco Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Documento Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Departam Docu Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Ciudad Docu Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Teléfono Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Celular Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Ocupación Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Dirección Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Username Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Email Acud1</th>
            <th style="background-color: #e0f1ff; vertical-align: middle">Observaciones Acud1</th>
            
            <th style="background-color: #c9f893; vertical-align: middle">ID Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Nombres Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Apellidos Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Sexo Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Tipo docu Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">¿Es el acudiente? Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Parentesco Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Documento Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Departam Docu Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Ciudad Docu Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Teléfono Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Celular Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Ocupación Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Dirección Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Username Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Email Acud2</th>
            <th style="background-color: #c9f893; vertical-align: middle">Observaciones Acud2</th>
        </tr>
    </thead>
    <tbody>
        @foreach($alumnos as $key=>$alumno)
        <tr>
            <td>{{++$key}}</td>
            <td>{{$alumno->alumno_id}}</td>
            <td>{{$alumno->tipo_doc_name}}</td>
            <td>{{$alumno->documento}}</td>
            <td>{{$alumno->departamento_doc_nombre}}</td>
            <td>{{$alumno->ciudad_doc_nombre}}</td>
            <td>{{$alumno->apellidos_divididos['first']}}</td>
            <td>{{$alumno->apellidos_divididos['last']}}</td>
            <td>{{$alumno->nombres_divididos['first']}}</td>
            <td>{{$alumno->nombres_divididos['last']}}</td>
            <td>{{$alumno->username}}</td>
            <td>{{$alumno->estado}}</td>
            <td>{{$alumno->no_matricula}}</td>
            <td>{{$alumno->direccion}}</td>
            <td>{{$alumno->barrio}}</td>
            <td>{{$alumno->ciudad_resid_nombre}}</td>
            <td>{{$alumno->es_urbana}}</td>
            <td>{{$alumno->telefono}}</td>
            <td>{{$alumno->celular}}</td>
            <td>{{$alumno->estrato}}</td>
            <td>{{$alumno->sisben}}</td>
            <td>{{$alumno->sisben_3}}</td>
            <td>{{$alumno->fecha_nac}}</td>
            <td>{{$alumno->departamento_nac_nombre}}</td>
            <td>{{$alumno->ciudad_nac_nombre}}</td>
            <td>{{$alumno->sexo}}</td>
            <td>{{$alumno->es_nuevo}}</td>
            <td>{{$alumno->tipo_sangre}}</td>
            <td>{{$alumno->eps}}</td>
            <td>{{$alumno->religion}}</td>
            
            <td>{{$alumno->acudientes[0]->id}}</td>
            <td>{{$alumno->acudientes[0]->nombres}}</td>
            <td>{{$alumno->acudientes[0]->apellidos}}</td>
            <td>{{$alumno->acudientes[0]->sexo}}</td>
            <td>{{$alumno->acudientes[0]->tipo_doc_nombre}}</td>
            <td>{{$alumno->acudientes[0]->es_acudiente}}</td>
            <td>{{$alumno->acudientes[0]->parentesco}}</td>
            <td>{{$alumno->acudientes[0]->documento}}</td>
            <td>{{$alumno->acudientes[0]->departamento_doc_nombre}}</td>
            <td>{{$alumno->acudientes[0]->ciudad_doc_nombre}}</td>
            <td>{{$alumno->acudientes[0]->telefono}}</td>
            <td>{{$alumno->acudientes[0]->celular}}</td>
            <td>{{$alumno->acudientes[0]->ocupacion}}</td>
            <td>{{$alumno->acudientes[0]->direccion}}</td>
            <td>{{$alumno->acudientes[0]->username}}</td>
            <td>{{$alumno->acudientes[0]->email}}</td>
            <td>{{$alumno->acudientes[0]->observaciones}}</td>
            
            <td>{{$alumno->acudientes[1]->id}}</td>
            <td>{{$alumno->acudientes[1]->nombres}}</td>
            <td>{{$alumno->acudientes[1]->apellidos}}</td>
            <td>{{$alumno->acudientes[1]->sexo}}</td>
            <td>{{$alumno->acudientes[1]->tipo_doc_nombre}}</td>
            <td>{{$alumno->acudientes[1]->es_acudiente}}</td>
            <td>{{$alumno->acudientes[1]->parentesco}}</td>
            <td>{{$alumno->acudientes[1]->documento}}</td>
            <td>{{$alumno->acudientes[1]->departamento_doc_nombre}}</td>
            <td>{{$alumno->acudientes[1]->ciudad_doc_nombre}}</td>
            <td>{{$alumno->acudientes[1]->telefono}}</td>
            <td>{{$alumno->acudientes[1]->celular}}</td>
            <td>{{$alumno->acudientes[1]->ocupacion}}</td>
            <td>{{$alumno->acudientes[1]->direccion}}</td>
            <td>{{$alumno->acudientes[1]->username}}</td>
            <td>{{$alumno->acudientes[1]->email}}</td>
            <td>{{$alumno->acudientes[1]->observaciones}}</td>
        </tr>
        @endforeach
    </tbody>
</table>