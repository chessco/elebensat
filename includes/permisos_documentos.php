<?php
function tipos_documento_todos(): array { return ['I','E','P','N','T']; }
function acciones_documento_todas(): array { return ['ver_xml_pdf','descargar_xml','descargar_pdf','exportar','procesar_pagos','generar_diot','ver_solicitudes_sat','contpaq_cheques','oracle_pagos','nomina_archivo','consulta_cruce_metadata','consulta_codigos_postales','consulta_sat_69','consulta_alertas_69','consulta_sat_69b','consulta_alertas_69b']; }
function usuario_es_superadmin(PDO $pdo,int $idUsuario): bool { $s=$pdo->prepare('SELECT es_superadmin FROM usuarios WHERE id_usuario=?');$s->execute([$idUsuario]);return (int)$s->fetchColumn()===1; }
function obtener_fila_permisos(PDO $pdo,int $u,int $e): ?array { $s=$pdo->prepare('SELECT * FROM usuario_empresa_documentos WHERE id_usuario=? AND id_empresa=? LIMIT 1');$s->execute([$u,$e]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null; }
function obtener_permisos_documentos(PDO $pdo,int $u,int $e): array {
 if($u<=0||$e<=0)return[];

 // La asignación explícita por empresa SIEMPRE manda, incluso para superadmin.
 // Esto permite que un superadmin tenga acceso administrativo al sistema pero
 // no pueda consultar Nómina u otro tipo de CFDI en una empresa determinada.
 $p=obtener_fila_permisos($pdo,$u,$e);
 if($p){
   $r=[];
   foreach(['I'=>'ver_ingreso','E'=>'ver_egreso','P'=>'ver_pago','N'=>'ver_nomina','T'=>'ver_traslado'] as $t=>$c)
     if((int)($p[$c]??0)===1)$r[]=$t;
   return $r;
 }

 // Compatibilidad: si no existe configuración explícita, el superadmin conserva
 // acceso total. Para usuarios normales se exige al menos relación con la empresa.
 if(usuario_es_superadmin($pdo,$u))return tipos_documento_todos();

 $s=$pdo->prepare('SELECT 1 FROM usuario_empresas WHERE id_usuario=? AND id_empresa=?');
 $s->execute([$u,$e]);
 if(!$s->fetchColumn())return[];

 // Comportamiento heredado para usuarios asignados que aún no tienen fila de permisos.
 return tipos_documento_todos();
}
function obtener_permisos_acciones(PDO $pdo,int $u,int $e): array {
 if($u<=0||$e<=0)return[];

 // Igual que los tipos de documento: una configuración explícita por empresa
 // debe respetarse aunque el usuario sea superadmin.
 $p=obtener_fila_permisos($pdo,$u,$e);
 if($p){
   $r=[];
   foreach(acciones_documento_todas() as $a){
     $def=in_array($a,['contpaq_cheques','oracle_pagos','nomina_archivo','consulta_cruce_metadata','consulta_codigos_postales','consulta_sat_69','consulta_alertas_69','consulta_sat_69b','consulta_alertas_69b'],true)?0:1;
     if((int)($p[$a]??$def)===1)$r[]=$a;
   }
   return $r;
 }

 if(usuario_es_superadmin($pdo,$u))return acciones_documento_todas();

 $s=$pdo->prepare('SELECT 1 FROM usuario_empresas WHERE id_usuario=? AND id_empresa=?');
 $s->execute([$u,$e]);
 if(!$s->fetchColumn())return[];

 return array_values(array_diff(acciones_documento_todas(),['contpaq_cheques','oracle_pagos','nomina_archivo','consulta_cruce_metadata','consulta_codigos_postales','consulta_sat_69','consulta_alertas_69','consulta_sat_69b','consulta_alertas_69b']));
}
function usuario_puede_accion(PDO $pdo,int $u,int $e,string $a): bool { return in_array($a,obtener_permisos_acciones($pdo,$u,$e),true); }
function usuario_puede_alguna_accion(PDO $pdo,int $u,int $e,array $acciones): bool { foreach($acciones as $a) if(usuario_puede_accion($pdo,$u,$e,(string)$a)) return true; return false; }
function exigir_permiso_accion_alguna(PDO $pdo,int $u,int $e,array $acciones): void { if(!usuario_puede_alguna_accion($pdo,$u,$e,$acciones)){http_response_code(403);throw new RuntimeException('No tienes permiso para realizar esta acción en la empresa activa.');} }
function exigir_permiso_accion(PDO $pdo,int $u,int $e,string $a): void { if(!usuario_puede_accion($pdo,$u,$e,$a)){http_response_code(403);throw new RuntimeException('No tienes permiso para realizar esta acción en la empresa activa.');} }
function tipo_empresarial_factura(array $f): string { $t=strtoupper(trim((string)($f['id_tipo_comprobante']??$f['tipo_sat']??''))); if(in_array($t,['P','N','T'],true))return$t; $m=strtoupper(trim((string)($f['tipo_movimiento_empresa']??''))); if(in_array($m,['I','E'],true))return$m; return in_array($t,['I','E'],true)?$t:''; }
function usuario_puede_ver_tipo(PDO $pdo,int $u,int $e,string $t): bool{return in_array(strtoupper($t),obtener_permisos_documentos($pdo,$u,$e),true);}
function exigir_permiso_factura_uuid(PDO $pdo,int $u,int $e,string $uuid): array { $s=$pdo->prepare('SELECT uuid,id_tipo_comprobante,tipo_movimiento_empresa FROM facturas WHERE uuid=? AND id_empresa=? LIMIT 1');$s->execute([$uuid,$e]);$f=$s->fetch(PDO::FETCH_ASSOC);if(!$f)throw new RuntimeException('Documento no encontrado en la empresa activa.');$t=tipo_empresarial_factura($f);if($t===''||!usuario_puede_ver_tipo($pdo,$u,$e,$t))throw new RuntimeException('No tienes permiso para consultar este tipo de documento.');$f['tipo_permiso']=$t;return$f; }
function sql_filtro_tipos_permitidos(array $p,array &$params,string $a='f'): string { $x=[]; if(in_array('I',$p,true))$x[]="({$a}.tipo_movimiento_empresa='I' AND UPPER(COALESCE({$a}.id_tipo_comprobante,'')) NOT IN ('P','N','T'))"; if(in_array('E',$p,true))$x[]="({$a}.tipo_movimiento_empresa='E' AND UPPER(COALESCE({$a}.id_tipo_comprobante,'')) NOT IN ('P','N','T'))"; foreach(['P','N','T'] as $t)if(in_array($t,$p,true)){ $k=':perm_'.strtolower($t);$x[]="UPPER(COALESCE({$a}.id_tipo_comprobante,''))={$k}";$params[$k]=$t;} return $x?'('.implode(' OR ',$x).')':'1=0'; }
?>
