@foreach($grupos as $key=>$grupo)

    @foreach($grupo->alumnos as $key=>$alumno)
        <div class="page-vertical no-padding-left salto-pagina">
            <div class="boletin-alumno">
                <div class="row encabezado-boletin">
                    <div class="col-lg-12 col-xs-12">
                        <div class="row">
                            <div class="col-lg-2 col-xs-2"><img class="img-responsive logo-colegio" src="{{ $dns }}{{ $year->logo }}"></div>
                            <div class="col-lg-8 col-xs-8 title-encabezado-boletin">
                                <div class="nombre-colegio ">{{$year->nombre_colegio}}</div>
                            <div class="resolucion-colegio">Aprobado bajo {{ $year->resolucion }} {{ $year->ciudad }} - {{ $year->departamento }}</div>
                                <div class="title-descripcion">OBSERVADOR DEL ALUMNO {{ $year->year}}</div>
                            </div>
                            <div class="col-lg-2 col-xs-2 texto-right">
                                <img src="{{ $dns }}{{ $alumno->foto_nombre }}" class="img-responsive img-thumbnail foto-alumno">
                            </div>
                        </div>
                    </div>
                </div>


                <div class="row">
                    <div class="col-xs-5 ">
                        NOMBRE: <span class="texto-negrita txt-12">{{$alumno->apellidos}} {{$alumno->nombres}} </span> 
                    </div>
                    <div class="col-xs-4">
                        NACIMIENTO: {{$alumno->fecha_nac}} {{$alumno->ciudad_nac_nombre}} 
                    </div>
                    
                    <div class="col-xs-3 texto-right">
                        GRUPO: <span class="texto-negrita">{{$grupo->nombre}} </span> 
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-xs-4">
                        DOCUMENTO: 
                        <span class="texto-negrita txt-12">{{$alumno->tipo_doc_abrev}} {{$alumno->documento}} 
                        </span>
                    </div>
                    <div class="col-xs-1">
                        RH: {{$alumno->tipo_sangre}} 
                    </div>
                    <div class="col-xs-3">
                        DIRECCIÓN: {{$alumno->direccion}} {{$alumno->barrio}} 
                    </div>
                    <div class="col-xs-4">
                        TEL: {{$alumno->celular}} - {{$alumno->telefono}}  
                    </div>
                </div>

                @foreach($alumno->acudientes as $key=>$acudiente)
                    @if ($loop->first)
                        <hr class="margin-top-5 margin-bottom-5">
                    @endif
                    
                    <div class="row">
                        <div class="col-xs-4">
                            {{ $acudiente->parentesco }}: {{$acudiente->nombres}} {{$acudiente->apellidos}}
                        </div>
                        <div class="col-xs-4">
                            DIRECCIÓN: {{$acudiente->direccion}} {{$acudiente->barrio}} 
                        </div>
                        <div class="col-xs-3">
                            TEL: {{$acudiente->celular}} - {{$acudiente->telefono}}  
                        </div>
                    </div>

                @endforeach
                
                <table class="tb-observador-alum">
                    <thead>
                        <tr>
                            <th class="tb-obsev-col-fecha text-align-center">FECHA</th>
                            <th class="tb-obsev-col-observador text-align-center">OBSERVACIONES SIGNIFICATIVAS</th>
                            <th class="tb-obsev-col-firma text-align-center">FIRMA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($filas as $key=>$value)
                        <tr>
                            <td height="30"></td>
                            <td height="30"></td>
                            <td height="30"></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="page-vertical no-padding-left @if (!$loop->last) salto-pagina @endif">
            <div class="boletin-alumno">
                <div class="row encabezado-boletin">
                    <div class="col-lg-12 col-xs-12">
                        <div class="row">
                            <div class="col-lg-2 col-xs-2"><img class="img-responsive logo-colegio" src="{{ $dns }}{{ $year->logo }}"></div>
                            <div class="col-lg-8 col-xs-8 title-encabezado-boletin">
                                <div class="nombre-colegio ">{{$year->nombre_colegio}}</div>
                            <div class="resolucion-colegio">Aprobado bajo {{ $year->resolucion }} {{ $year->ciudad }} - {{ $year->departamento }}</div>
                                <div class="title-descripcion">OBSERVACIONES POR PERIODOS {{ $year->year}}</div>
                            </div>
                            <div class="col-lg-2 col-xs-2 texto-right">
								@if ($alumno->foto_id)
									<img src="{{ $dns }}{{ $alumno->foto_nombre }}" class="img-responsive img-thumbnail foto-alumno">
								@endif
							</div>
                        </div>
                    </div>
                </div>
                @foreach($periodos as $key=>$periodo)
                    <div><span class="toast-title">Periodo {{$periodo->numero}}.</span> Desde el {{$periodo->fecha_inicio}} hasta el {{$periodo->fecha_fin}}</div>
                    <table class="tb-observador-alum">
                        <thead>
                            <tr>
                                <th class="tb-obsev-col-fecha text-align-center">FECHA</th>
                                <th class="tb-obsev-col-observador text-align-center">OBSERVACIONES DESCRIPTIVAS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($filasPer as $key=>$value)
                            <tr>
                                <td height="30"></td>
                                <td height="30"></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
                <div class="text-align-center">
                    <div class="text-align-center">ESCALA CONCEPTUAL DEL COMPORTAMIENTO Y ÁREAS QUE REQUIEREN REFUERZO</div>
                    <table class="tb-observador-alum-notas text-align-center">
                        <thead>
                            <tr>
                                <th class="text-align-center">Periodo 1</th>
                                <th class="text-align-center">Periodo 2</th>
                                <th class="text-align-center">Periodo 3</th>
                                <th class="text-align-center">Periodo 4</th>
                                <th class="text-align-center">Definitiva Año</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td height="70"></td>
                                <td height="70"></td>
                                <td height="70"></td>
                                <td height="70"></td>
                                <td height="70"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
            </div>
        </div>
    @endforeach



@endforeach