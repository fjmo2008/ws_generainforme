<?php
    //include('cabecera.php');
    // Hay que poner estas cabeceras para que el cliente de este web service no de error de COORS
    header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');

    require_once 'config.php';

    $metodo = $_SERVER['REQUEST_METHOD']; 

    // Getting the received ID in JSON Format into $json variable.
    $json = file_get_contents('php://input');

    // Decoding the received JSON.
    $obj = json_decode($json,true);

    // Populate ID from JSON $obj array and store into $ID variable.
    // $param_fechad = "03-09-2024";
    // $param_fechah = "03-09-2024";
    // $vista = "V03_INFORMESALTASAS_COPA05_18B";
    // $centro = "03";
    // $fecha_generado = "20241002183259";

    // Populate ID from JSON $obj array and store into $ID variable.
    $param_fechad = $obj["fechad"];
    $param_fechah = $obj["fechah"];
    $vista = $obj["vista"];
    $centro = $obj["codigo_centro"];
    $fecha_generado = $obj["fecha_generada"];



    $urlvistas = Url::VISTAS.$vista;

    //  Initiate curl
    $ch = curl_init();
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL,$urlvistas);
    // Execute
    $result=json_decode(curl_exec($ch),true);
    // Closing
    curl_close($ch);

    $formato = $result;

   foreach($formato as $row) {
        $separador = $row['SEPARADOR'];
        $estructura = $row['ESTRUCTURA'];
        $arr_campos = explode(",", $estructura);
        $sas = $row['SAS'];
    }


    // llama al webservice con la vista que corresponde al centro del usuario
    $url = Url::CONSULTA.$param_fechad."/".$param_fechah."/".$vista;

    //  Initiate curl
    $ch = curl_init();
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL,$url);
    // Execute
    $result=json_decode(curl_exec($ch),true);
    // Closing
    curl_close($ch);

    $tabla = $result;

    // IP desde la que se ejecuta el procedimiento
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    // Ponemos el semaforo para que el proceso de copiado de las carpetas sepa cuando puede hacer la copia
    $semaforo_contenido = $ip.',"'.date('Y/m/d H:i:s').'","'.$param_fechad.'"';
    $semaforo_fichero = './sem/mover.lock';
    file_put_contents($semaforo_fichero,$semaforo_contenido);

    // Comenzamos a crear el archivo comprimido donde irán los pdfs que se descargarán también de forma automática
    $zip = new ZipArchive();
    $nombrezip = "./zip/".$centro."/".$centro."_".$fecha_generado."_".str_replace('-','',$param_fechad)."_".str_replace('-','',$param_fechah).".zip";

    // ****************************************************************************************************
    //
    //  Servicio SOAP para recuperar los ficheros pdf de Servolab
    //
    // ****************************************************************************************************
    // servicio SOAP de produccion
    $servicio = Url::SOAP; //url del servicio
    
    // Parametros a pasar al ws SOAP
    $parametros['usuario']=Url::SOAP_USER;
    $parametros['pass']=Url::SOAP_PASS;

    // Establecemos el canal de comunicación con el servicio
    $client = new SoapClient($servicio, array('trace' => 1, 'exceptions' => 0));


    $zip->open($nombrezip,ZipArchive::CREATE);

    
    // Abrimos el fichero log para añadir el registro de lo que se genera como PDF
    $fp = fopen("./logpdf.log","a");

    $tablaResultado = [];
    $i=1;
    // recorremos todos los pacientes para generar los informes
    foreach($tabla as $row) {
        // El nombre del fichero está vacío hasta que no se genere el informe
        $row['FICHERO'] = '';

        // Si es con formato SAS se generan de esta forma
        if ($sas == 'S') {

            // Debe tener el NUHSA y el CENTRO_REMITE
            if (!empty($row['NUHSA']) and !empty($row['CENTRO_REMITE'])) {

                // Informe de TiCares FIRMADO
                if ($row['ESTADO']=='Firmado') {
                    $url_inf = Url::INFORME_TICARES_01.$row['SID_DOCUMENTO'].Url::INFORME_TICARES_02;

                    $arrContextOptions=array(
                        "ssl"=>array(
                            "verify_peer"=>false,
                            "verify_peer_name"=>false,
                        ),
                    );
                    $informe = file_get_contents($url_inf, false, stream_context_create($arrContextOptions));
                }

                // Informe de laboratorio desde Servolab para Sevilla
                if ($row['ESTADO']=='LABOR') {
                    $parametros['sidInforme']=$row['SID_DOCUMENTO'];
                    $parametros['tipoInforme']="PD";
                    $result = $client->informePdfTipoInforme($parametros);//llamamos al método que nos interesa con los parámetros

                    if (is_soap_fault($result)) {
                        echo "error<br>";
                        trigger_error("SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})", E_USER_ERROR);
                    } else {
                        $informe = $result->InformePdf; // Cargo el PDF en la variable
                    }
                }

                // Informe subido a TiCares en formato PDF
                if ($row['ESTADO']=='PDF') {
                    $url_inf = Url::INFORME_PDF_01.$row['SID_DOCUMENTO'].Url::INFORME_PDF_02;

                    $arrContextOptions=array(
                        "ssl"=>array(
                            "verify_peer"=>false,
                            "verify_peer_name"=>false,
                        ),
                        "https"=>array(
                            "timeout"=>120,
                            "user_agent"=>"Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko",
                        ),
                    );

                    $informe = file_get_contents($url_inf, false, stream_context_create($arrContextOptions));
                
                    fputs($fp, $url_inf.chr(10));
/*
                    $contador_campo = 1;
                    $rutapdf = './pdf/03/';
                    $ficheropdf = $i;
                    $ficheropdf = $ficheropdf.'.pdf';
                    $ficheropdfzip = $ficheropdf;
                    $bytesEscritos = file_put_contents($rutapdf.$ficheropdf,$informe);  // Añade el nº de bytes escritos en el fichero
                    echo $ficheropdfzip.' - ['.$bytesEscritos.']';

                    // Añadimos el fichero pdf al zip
                    $zip->addFile($rutapdf.$ficheropdf,$ficheropdfzip);
                    $i++;
*/                    
                }

                // Una vez obtenido el informe, lo guardamos en el fichero con el formato correspondiente
                if ($row['ESTADO']=='PDF' or $row['ESTADO']=='Firmado' or $row['ESTADO']=='LABOR') { 
                    // Genera el nombre del fichero con los campos y el separador definido en el formato
                    // Hay que comprobar si hay campos en la definicion del formato o no, en caso de que no, no se podrán generar los ficheros pdf
                    $contador_campo = 1;
                    $rutapdf = './pdf/'.$centro.'/';
                    $ficheropdf = '';
                    foreach($arr_campos as $row_campo) {
                        if (substr($row_campo,0,1) == "$") {
                            $ficheropdf = $ficheropdf.$separador.substr($row_campo,1);
                        } else {
                            if ($contador_campo == 1) {
                                $ficheropdf = $ficheropdf.$row[$row_campo];
                            } else {
                                $ficheropdf = $ficheropdf.$separador.$row[$row_campo];
                            }
                            $contador_campo ++;
                        }
                    }
                    $ficheropdf = $ficheropdf.'.pdf';
                    $ficheropdfzip = $ficheropdf;
                    $bytesEscritos = file_put_contents($rutapdf.$ficheropdf,$informe);  // Añade el nº de bytes escritos en el fichero
                    $row['FICHERO'] = $ficheropdfzip.' - ['.$bytesEscritos.']';

                    // Añadimos el fichero pdf al zip
                    $zip->addFile($rutapdf.$ficheropdf,$ficheropdfzip);

                    //echo $row['FICHERO']."<br>";
                }                

            }
        } 
        // En caso contrario que no sean con formato SAS, usan esta otra
        else {

            // El informe de TiCares tiene que estar firmado
            if ($row['ESTADO']=='Firmado') {
                // $informe = file_get_contents(Url::INFORME_TICARES_01.$row['SID_DOCUMENTO'].Url::INFORME_TICARES_02); // original
                $url_inf = Url::INFORME_TICARES_01.$row['SID_DOCUMENTO'].Url::INFORME_TICARES_02;

                $arrContextOptions=array(
                    "ssl"=>array(
                        "verify_peer"=>false,
                        "verify_peer_name"=>false,
                    ),
                );
                $informe = file_get_contents($url_inf, false, stream_context_create($arrContextOptions));

                // Genera el nombre del fichero con los campos y el separador definido en el formato
                // Hay que comprobar si hay campos en la definicion del formato o no, en caso de que no, no se podrán generar los ficheros pdf
                $contador_campo = 1;
                $rutapdf = './pdf/'.$centro.'/';
                $ficheropdf = '';
                foreach($arr_campos as $row_campo) {
                    if (substr($row_campo,0,1) == "$") {
                        $ficheropdf = $ficheropdf.$separador.substr($row_campo,1);
                    } else {
                        if ($contador_campo == 1) {
                            $ficheropdf = $ficheropdf.$row[$row_campo];
                        } else {
                            $ficheropdf = $ficheropdf.$separador.$row[$row_campo];
                        }
                        $contador_campo ++;
                    }
                }
                $ficheropdf = $ficheropdf.'.pdf';
                $ficheropdfzip = $ficheropdf;
                $bytesEscritos = file_put_contents($rutapdf.$ficheropdf,$informe);
                $row['FICHERO'] = $ficheropdfzip;

                // Añadimos el fichero pdf al zip
                $zip->addFile($rutapdf.$ficheropdf,$ficheropdfzip);
            }

        }

        array_push($tablaResultado, $row);

    }

    // Cerramos el fichero de log
    fclose($fp);

    // Cerramos el zip
    $zip->close();

    // Quitamos el semáforo
    unlink($semaforo_fichero);

    // El array $tablaResultado contiene todos los pacientes con sus informes y el nombre del fichero que se ha generado.
    // En caso de que no se genere el fichero por cualquiera de las condiciones que no lo genere, 
    // el nombre del fichero estara vacio 
    echo json_encode($tablaResultado);

?>

