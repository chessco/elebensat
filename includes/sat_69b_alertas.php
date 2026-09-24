<?php
/**
 * Cruce del catálogo oficial SAT 69-B contra el catálogo local de emisores.
 * Conserva el seguimiento del usuario aunque el catálogo SAT se vuelva a sincronizar.
 */
require_once __DIR__ . '/sat_cruce_job.php';

function sat69b_crear_tabla_alertas(PDO $pdo): void
{
    // Producción: el usuario del portal NO tiene permiso CREATE.
    // Las tablas se crean una sola vez mediante el SQL de instalación.
    $st = $pdo->query("SHOW TABLES LIKE 'sat_69b_alertas_emisores'");
    if (!$st || !$st->fetchColumn()) {
        throw new RuntimeException(
            "Falta la tabla sat_69b_alertas_emisores. Ejecute el SQL de instalación con un usuario administrador."
        );
    }
}

function sat69b_fecha_relevante_sql(): string
{
    return "CASE
        WHEN s.situacion='Definitivo' THEN COALESCE(NULLIF(s.fecha_sat_definitivos,''), NULLIF(s.fecha_dof_definitivos,''), NULLIF(s.fecha_sat_presuntos,''), NULLIF(s.fecha_dof_presuntos,''))
        WHEN s.situacion='Presunto' THEN COALESCE(NULLIF(s.fecha_sat_presuntos,''), NULLIF(s.fecha_dof_presuntos,''))
        WHEN s.situacion='Desvirtuado' THEN COALESCE(NULLIF(s.fecha_sat_desvirtuados,''), NULLIF(s.fecha_dof_desvirtuados,''), NULLIF(s.fecha_sat_presuntos,''))
        WHEN s.situacion='Sentencia Favorable' THEN COALESCE(NULLIF(s.fecha_sat_sentencia,''), NULLIF(s.fecha_dof_sentencia,''), NULLIF(s.fecha_sat_definitivos,''))
        ELSE NULL END";
}

function sat69b_cruzar_emisores(PDO $pdo, string $jobId = '', int $idEmpresa = 0): array
{
    sat69b_crear_tabla_alertas($pdo);

    if ($idEmpresa > 0) {
        $stIds = $pdo->prepare(
            "SELECT id_emisor
               FROM cat_emisores
              WHERE id_empresa=?
                AND TRIM(COALESCE(rfc,'')) <> ''
              ORDER BY id_emisor"
        );
        $stIds->execute([$idEmpresa]);
        $ids = $stIds->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $ids = $pdo->query(
            "SELECT id_emisor
               FROM cat_emisores
              WHERE TRIM(COALESCE(rfc,'')) <> ''
              ORDER BY id_emisor"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    $ids = array_map('intval', $ids ?: []);
    $total = count($ids);
    if ($jobId !== '') sat_cruce_job_iniciar($jobId, '69b', $total);

    $bloque = 200;
    $procesados = 0;
    $fecha = sat69b_fecha_relevante_sql();

    try {
        $pdo->beginTransaction();

        // Todo el cruce es atómico: cancelar = ROLLBACK.
        if ($idEmpresa > 0) {
            $stDesactiva = $pdo->prepare("UPDATE sat_69b_alertas_emisores SET activa=0 WHERE id_empresa=?");
            $stDesactiva->execute([$idEmpresa]);
        } else {
            $pdo->exec("UPDATE sat_69b_alertas_emisores SET activa=0");
        }

        foreach (array_chunk($ids, $bloque) as $grupoIds) {
            if ($jobId !== '' && sat_cruce_job_cancelacion_solicitada($jobId)) {
                throw new SatCruceCanceladoException('Cruce cancelado por el usuario.');
            }

            $marks = implode(',', array_fill(0, count($grupoIds), '?'));
            $sql = "INSERT INTO sat_69b_alertas_emisores
                (id_empresa,id_emisor,id_69b,rfc,nombre_emisor,nombre_sat,situacion,fecha_publicacion_relevante,activa,fecha_primera_deteccion,fecha_ultima_deteccion)
                SELECT e.id_empresa,e.id_emisor,s.id_69b,UPPER(TRIM(e.rfc)),e.nombre,
                       s.nombre_contribuyente,s.situacion,$fecha,1,NOW(),NOW()
                  FROM cat_emisores e
                  INNER JOIN sat_69b_contribuyentes s
                    ON BINARY UPPER(TRIM(s.rfc)) = BINARY UPPER(TRIM(e.rfc))
                 WHERE e.id_emisor IN ($marks)
                   AND TRIM(COALESCE(e.rfc,'')) <> ''
                ON DUPLICATE KEY UPDATE
                    id_empresa=VALUES(id_empresa),
                    rfc=VALUES(rfc),
                    nombre_emisor=VALUES(nombre_emisor),
                    nombre_sat=VALUES(nombre_sat),
                    situacion=VALUES(situacion),
                    fecha_publicacion_relevante=VALUES(fecha_publicacion_relevante),
                    activa=1,
                    fecha_ultima_deteccion=NOW()";

            $st = $pdo->prepare($sql);
            $st->execute($grupoIds);

            $procesados += count($grupoIds);
            if ($jobId !== '') {
                sat_cruce_job_actualizar(
                    $jobId,
                    $procesados,
                    $total,
                    "Revisando emisores {$procesados} de {$total}..."
                );
            }
        }

        if ($jobId !== '' && sat_cruce_job_cancelacion_solicitada($jobId)) {
            throw new SatCruceCanceladoException('Cruce cancelado por el usuario.');
        }

        $pdo->commit();

        $totalCoincidentes = (int)$pdo->query("SELECT COUNT(DISTINCT id_emisor) FROM sat_69b_alertas_emisores WHERE activa=1" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : ""))->fetchColumn();
        $riesgo = (int)$pdo->query("SELECT COUNT(DISTINCT id_emisor) FROM sat_69b_alertas_emisores WHERE activa=1 AND situacion IN ('Presunto','Definitivo')" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : ""))->fetchColumn();
        $nuevas = (int)$pdo->query("SELECT COUNT(DISTINCT id_emisor) FROM sat_69b_alertas_emisores WHERE activa=1 AND estatus_seguimiento='NUEVA' AND situacion IN ('Presunto','Definitivo')" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : ""))->fetchColumn();

        $resumen = [
            'total_emisores'=>$totalCoincidentes,
            'emisores_riesgo'=>$riesgo,
            'nuevas'=>$nuevas
        ];

        if ($jobId !== '') {
            sat_cruce_job_finalizar($jobId, 'terminado', 'Cruce 69-B terminado.', [
                'resumen' => $resumen
            ]);
        }

        return $resumen;

    } catch (SatCruceCanceladoException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($jobId !== '') {
            sat_cruce_job_finalizar(
                $jobId,
                'cancelado',
                'Cruce cancelado. No se guardaron cambios parciales.',
                ['procesados' => $procesados, 'total' => $total]
            );
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($jobId !== '') {
            sat_cruce_job_finalizar($jobId, 'error', 'El cruce terminó con error.', [
                'detalle' => $e->getMessage()
            ]);
        }
        throw $e;
    }
}
