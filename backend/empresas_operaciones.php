<?php
session_start();
require_once '../config/db.php';
require_once '../includes/seguridad.php';
require_once '../includes/correo_crypto.php';
seguridad_exigir_superadmin($pdo, false);
header('Content-Type: application/json');

$accion = $_POST['accion'] ?? '';

if ($accion == 'guardar') {
    try {
        $id = trim((string)($_POST['id_empresa'] ?? ''));

        // Normalización de entradas para que el guardado se comporte igual en
        // MariaDB/MySQL con SQL_MODE estricto y no estricto. En especial, los
        // campos numéricos vacíos deben enviarse como NULL y no como ''.
        $postTexto = static function (string $campo, string $default = ''): string {
            return trim((string)($_POST[$campo] ?? $default));
        };
        $postNullableInt = static function (string $campo): ?int {
            $valor = trim((string)($_POST[$campo] ?? ''));
            return $valor === '' ? null : (int)$valor;
        };
        $postNullableFecha = static function (string $campo): ?string {
            $valor = trim((string)($_POST[$campo] ?? ''));
            return $valor === '' ? null : $valor;
        };

        // Estado real de automatización. Se usa un campo oculto porque los checkbox
        // desmarcados no viajan en serialize(). Además protegemos una validación
        // recién confirmada para que GUARDAR EMPRESA no la apague accidentalmente.
        $automatizacionSolicitada = (($_POST['descarga_sat_automatica_val'] ?? '0') === '1') ? 1 : 0;
        $validacionConfirmada = (($_POST['pfx_fiel_validacion_confirmada'] ?? '0') === '1');
        $pfxFueModificado = (($_POST['pfx_fiel_modificado'] ?? '0') === '1');

        $estadoActualSat = null;
        if (!empty($id)) {
            $qEstado = $pdo->prepare("SELECT pfx_fiel_validado, descarga_sat_automatica, pfx_fiel_rfc, pfx_fiel_fecha_inicio, pfx_fiel_fecha_vencimiento, pfx_fiel_ultima_validacion FROM empresas WHERE id_empresa=?");
            $qEstado->execute([$id]);
            $estadoActualSat = $qEstado->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($validacionConfirmada && $estadoActualSat && (int)$estadoActualSat['pfx_fiel_validado'] === 1) {
                // Conservamos los datos de validación del PFX, pero respetamos el
                // estado solicitado por el usuario. Una FIEL válida puede quedar
                // temporalmente desactivada para que el worker no la procese.
                $_POST['pfx_fiel_rfc'] = $estadoActualSat['pfx_fiel_rfc'];
                $_POST['pfx_fiel_fecha_inicio'] = $estadoActualSat['pfx_fiel_fecha_inicio'];
                $_POST['pfx_fiel_fecha_vencimiento'] = $estadoActualSat['pfx_fiel_fecha_vencimiento'];
                $_POST['pfx_fiel_ultima_validacion'] = $estadoActualSat['pfx_fiel_ultima_validacion'];
            }
        }
        
        // Parámetros básicos (ordenados secuencialmente para el SQL)
        $p = [
            $postNullableInt('id_zona'),
            strtoupper($postTexto('rfc')),
            strtoupper($postTexto('tipopersona')),
            $postTexto('modo_diot', 'normal') ?: 'normal',
            strtoupper($postTexto('criterio_fecha_pue', 'EMISION')) ?: 'EMISION',
            $postTexto('razon_social'),
            $postTexto('comercial'),
            $postTexto('calle'),
            $postTexto('colonia'),
            $postTexto('nexterior'),
            $postTexto('ninterior'),
            $postTexto('referencia'),
            $postTexto('ciudad'),
            $postTexto('municipio'),
            $postTexto('estado'),
            $postTexto('pais'),
            $postTexto('codigopostal'),
            $postTexto('telefono'),
            $postTexto('correo'),
            $postTexto('lugarexpedicion'),
            $postTexto('regimenfiscal'),
            $postTexto('passfiel'),
            $postTexto('passcsd'),
            $postTexto('pass_pfx_fiel'),
            $postTexto('pass_pfx_csd'),
            $automatizacionSolicitada,
            $postTexto('pfx_fiel_rfc'),
            $postNullableFecha('pfx_fiel_fecha_inicio'),
            $postNullableFecha('pfx_fiel_fecha_vencimiento'),
            $postNullableFecha('pfx_fiel_ultima_validacion'),
            $postTexto('smptp_correo'),
            $postNullableInt('puerto_correo'),
            isset($_POST['usa_aut']) ? 1 : 0,
            isset($_POST['usa_ssl']) ? 1 : 0,
            $postTexto('asunto'),
            $postTexto('mensaje'),
            $postTexto('correo_remitente'),
            $postTexto('nombre_remitente'),
            $postTexto('reply_to_correo'),
            $postTexto('smtp_usuario'),
            strtoupper($postTexto('seguridad_correo', 'STARTTLS')) ?: 'STARTTLS'
        ];

        if (empty($id)) {
            // INSERT
            $sql = "INSERT INTO empresas (
                id_zona, rfc, tipopersona, modo_diot, criterio_fecha_pue, razon_social, 
                comercial, calle, colonia, nexterior, ninterior, referencia, 
                ciudad, municipio, estado, pais, codigopostal, telefono, 
                correo, lugarexpedicion, regimenfiscal, passfiel, passcsd, pass_pfx_fiel, pass_pfx_csd,
                descarga_sat_automatica, pfx_fiel_rfc, pfx_fiel_fecha_inicio, pfx_fiel_fecha_vencimiento, pfx_fiel_ultima_validacion,
                smptp_correo, puerto_correo, usa_aut, usa_ssl, asunto, mensaje, correo_remitente, nombre_remitente, reply_to_correo, smtp_usuario, seguridad_correo, smtp_password_enc, 
                certificado_fiel, llave_fiel, certificado_csd, llave_csd, pfx_fiel, pfx_csd, logo1, logo2
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            
            // Credencial SMTP: se cifra con la llave general ubicada fuera de htdocs.
            $smtpPasswordPlano = (string)($_POST['smtp_password'] ?? '');
            $p[] = $smtpPasswordPlano !== '' ? correo_cifrar($smtpPasswordPlano) : null;

            // Adjuntar archivos opcionales al final del array
            array_push($p, 
                $_POST['fiel_b64']??null, 
                $_POST['fiel_key_b64']??null, 
                $_POST['csd_b64']??null, 
                $_POST['csd_key_b64']??null, 
                $_POST['pfx_fiel_b64']??null,
                $_POST['pfx_csd_b64']??null,
                $_POST['logo1_b64']??null, 
                $_POST['logo2_b64']??null
            );

        } else {
            // UPDATE
            $sql = "UPDATE empresas SET 
                id_zona=?, rfc=?, tipopersona=?, modo_diot=?, criterio_fecha_pue=?, razon_social=?, 
                comercial=?, calle=?, colonia=?, nexterior=?, ninterior=?, referencia=?, 
                ciudad=?, municipio=?, estado=?, pais=?, codigopostal=?, telefono=?, 
                correo=?, lugarexpedicion=?, regimenfiscal=?, passfiel=?, passcsd=?, pass_pfx_fiel=?, pass_pfx_csd=?,
                descarga_sat_automatica=?, pfx_fiel_rfc=?, pfx_fiel_fecha_inicio=?, pfx_fiel_fecha_vencimiento=?, pfx_fiel_ultima_validacion=?,
                smptp_correo=?, puerto_correo=?, usa_aut=?, usa_ssl=?, asunto=?, mensaje=?, correo_remitente=?, nombre_remitente=?, reply_to_correo=?, smtp_usuario=?, seguridad_correo=?";

            $smtpPasswordPlano = (string)($_POST['smtp_password'] ?? '');
            if ($smtpPasswordPlano !== '') { $sql .= ", smtp_password_enc=?"; $p[] = correo_cifrar($smtpPasswordPlano); }
            
            if(!empty($_POST['fiel_b64'])) { $sql .= ", certificado_fiel=?"; $p[] = $_POST['fiel_b64']; }
            if(!empty($_POST['fiel_key_b64'])) { $sql .= ", llave_fiel=?"; $p[] = $_POST['fiel_key_b64']; }
            if(!empty($_POST['csd_b64'])) { $sql .= ", certificado_csd=?"; $p[] = $_POST['csd_b64']; }
            if(!empty($_POST['csd_key_b64'])) { $sql .= ", llave_csd=?"; $p[] = $_POST['csd_key_b64']; }
            if(!empty($_POST['pfx_fiel_b64'])) { $sql .= ", pfx_fiel=?"; $p[] = $_POST['pfx_fiel_b64']; }
            // Solo se invalida cuando realmente cambió el PFX/contraseña y todavía no
            // fue validado nuevamente mediante VALIDAR Y ACTIVAR.
            if($pfxFueModificado && !$validacionConfirmada) {
                $sql .= ", pfx_fiel_validado=0, descarga_sat_automatica=0, pfx_fiel_rfc=NULL, pfx_fiel_fecha_inicio=NULL, pfx_fiel_fecha_vencimiento=NULL, pfx_fiel_ultima_validacion=NULL";
            }
            if(!empty($_POST['pfx_csd_b64'])) { $sql .= ", pfx_csd=?"; $p[] = $_POST['pfx_csd_b64']; }
            if(!empty($_POST['logo1_b64'])) { $sql .= ", logo1=?"; $p[] = $_POST['logo1_b64']; }
            if(!empty($_POST['logo2_b64'])) { $sql .= ", logo2=?"; $p[] = $_POST['logo2_b64']; }
            
            $sql .= " WHERE id_empresa=?"; 
            $p[] = $id;
        }
        
        $pdo->prepare($sql)->execute($p);

        if (empty($id)) {
            $id = (int)$pdo->lastInsertId();
        } else {
            $id = (int)$id;
        }

        echo json_encode(['success' => true, 'id_empresa' => $id]);

    } catch (Throwable $e) {
        // El detalle técnico queda únicamente en el log del servidor para no
        // exponer nombres de tablas, rutas o datos sensibles al navegador.
        seguridad_log_error($e, 'empresas_operaciones');
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo completar la operación de empresa.'
        ]);
    }
}

if ($accion == 'estado') {
    try {
        $id = (int)($_POST['id_empresa'] ?? 0);
        $activo = ((string)($_POST['activo'] ?? '0') === '1') ? 1 : 0;
        if ($id <= 0) throw new RuntimeException('Empresa inválida.');
        $stmt = $pdo->prepare("UPDATE empresas SET activo=? WHERE id_empresa=?");
        $stmt->execute([$activo, $id]);
        echo json_encode(['success' => true, 'activo' => $activo]);
    } catch (Throwable $e) {
        seguridad_log_error($e, 'empresas_estado');
        echo json_encode(['success' => false, 'error' => 'No fue posible cambiar el estado de la empresa.']);
    }
}

if ($accion == 'eliminar') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'La eliminación de empresas está deshabilitada. Use Desactivar.']);
}
?>