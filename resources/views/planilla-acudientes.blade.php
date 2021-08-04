<h3>{{$grupo->nombre}} - {{$grupo->nombres_titular}} {{$grupo->apellidos_titular}}</h3>
<table >
    <thead>
        <tr>
            <th style="background-color: #c9f893; vertical-align: middle; border: 1px solid #000">No</th>
            <th style="background-color: #c9f893; vertical-align: middle; border: 1px solid #000">Alumnos</th>
            <th style="background-color: #c9f893; vertical-align: middle; border: 1px solid #000">Acudientes</th>
            <th style="background-color: #c9f893; vertical-align: middle; border: 1px solid #000">Firma</th>
            
        </tr>
    </thead>
    <tbody>
        @foreach($acudientes as $key=>$acudiente)
            <tr>
                <td style="border: 1px solid #000">{{++$key}}</td>
                <td style="border: 1px solid #000">{{$acudiente->documento}}</td>
                <td style="border: 1px solid #000">{{$acudiente->departamento_doc_nombre}}</td>
                <td style="border: 1px solid #000">{{$acudiente->ciudad_doc_nombre}}</td>
                <td style="font-weight: bold; border: 1px solid #000">{{$acudiente->nombres}}</td>
                <td style="font-weight: bold; border: 1px solid #000">{{$acudiente->apellidos}}</td>
                <td style="border: 1px solid #000">{{$acudiente->username}}</td>
                
            </tr>

        @endforeach
        
                
        <tr>
            <td></td>
            <td colspan="3"></td>
            
        </tr>
    </tbody>
</table>