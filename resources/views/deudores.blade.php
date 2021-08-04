<table >
    <thead>
        <tr>
            <th style="background-color: #fff7ad; vertical-align: middle">No</th>
            <th style="background-color: #fff7ad; vertical-align: middle">ID</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Apellidos</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Nombres</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Última fecha</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Deuda</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Grupo</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Documento</th>
            <th style="background-color: #fff7ad; vertical-align: middle">Celular</th>
        </tr>
    </thead>
    <tbody>
        @foreach($alumnos as $key=>$alumno)
        <tr>
            <td>{{++$key}}</td>
            <td>{{$alumno->alumno_id}}</td>
            <td>{{$alumno->apellidos}}</td>
            <td>{{$alumno->nombres}}</td>
            <td>{{$alumno->fecha_pension}}</td>
            <td>{{$alumno->deuda}}</td>
            <td>{{$alumno->nombre_grupo}}</td>
            <td>{{$alumno->documento}}</td>
            <td>{{$alumno->celular}}</td>
        </tr>
        @endforeach
    </tbody>
</table>