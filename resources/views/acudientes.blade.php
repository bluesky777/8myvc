<h2>{{$grupo->nombre}} - {{$grupo->nombres_titular}} {{$grupo->apellidos_titular}}</h2>
<table >
    <thead>
        <tr>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">No</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">ID</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Tipo de Documento</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Nro de documento</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Lugar de Expedición Departamento</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Lugar de Expedición Ciudad</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Apellidos</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Nombres</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Usuario</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Teléfono</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Celular</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Fecha de nacim</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Ciudad nacimiento</th>
            <th style="background-color: #fff7ad; vertical-align: middle; border: 1px solid #000">Sexo</th>
            
        </tr>
    </thead>
    <tbody>
        @foreach($acudientes as $key=>$acudiente)
            <tr>
                <td style="border: 1px solid #000">{{++$key}}</td>
                <td style="border: 1px solid #000">{{$acudiente->id}}</td>
                <td style="border: 1px solid #000">{{$acudiente->tipo_doc_nombre}}</td>
                <td style="border: 1px solid #000">{{$acudiente->documento}}</td>
                <td style="border: 1px solid #000">{{$acudiente->departamento_doc_nombre}}</td>
                <td style="border: 1px solid #000">{{$acudiente->ciudad_doc_nombre}}</td>
                <td style="font-weight: bold; border: 1px solid #000">{{$acudiente->nombres}}</td>
                <td style="font-weight: bold; border: 1px solid #000">{{$acudiente->apellidos}}</td>
                <td style="border: 1px solid #000">{{$acudiente->username}}</td>
                <td style="border: 1px solid #000">{{$acudiente->telefono}}</td>
                <td style="border: 1px solid #000">{{$acudiente->celular}}</td>
                <td style="border: 1px solid #000">{{$acudiente->fecha_nac}}</td>
                <td style="border: 1px solid #000">{{$acudiente->ciudad_nac_nombre}}</td>
                <td style="border: 1px solid #000">{{$acudiente->sexo}}</td>
                
            </tr>

            <tr>
                <td></td>
                <td style="background-color: #ccc; border: 1px solid #000">Gruop</td>
                <td style="background-color: #ccc; border: 1px solid #000">Documento</td>
                <td style="background-color: #ccc; border: 1px solid #000">Nombres</td>
                <td style="background-color: #ccc; border: 1px solid #000">Apellidos</td>
                <td style="background-color: #ccc; border: 1px solid #000">Usuario</td>
                <td style="background-color: #ccc; border: 1px solid #000">Telefono</td>
                <td style="background-color: #ccc; border: 1px solid #000">Celular</td>
                <td style="background-color: #ccc; border: 1px solid #000">Fecha nac</td>
                <td style="background-color: #ccc; border: 1px solid #000">Sexo</td>
                <td colspan="4"></td>
                
            </tr>
                
                @foreach($acudiente->alumnos as $key=>$alumno)
                <tr>
                    <td></td>
                    <td style="font-weight: bold; background-color: #c9f893; border: 1px solid #000">{{$alumno->nombre_grupo}}</td>
                    <td style="border: 1px solid #000">{{$alumno->documento}}</td>
                    <td style="font-weight: bold; border: 1px solid #000">{{$alumno->nombres}}</td>
                    <td style="font-weight: bold; border: 1px solid #000">{{$alumno->apellidos}}</td>
                    <td style="border: 1px solid #000">{{$alumno->username}}</td>
                    <td style="border: 1px solid #000">{{$alumno->telefono}}</td>
                    <td style="border: 1px solid #000">{{$alumno->celular}}</td>
                    <td style="border: 1px solid #000">{{$alumno->fecha_nac}}</td>
                    <td style="border: 1px solid #000">{{$alumno->sexo}}</td>
                    <td colspan="4" style="border: 1px solid #000"></td>
                    
                </tr>
                @endforeach
                <tr>
                    <td></td>
                    <td colspan="13"></td>
                    
                </tr>
        @endforeach
    </tbody>
</table>