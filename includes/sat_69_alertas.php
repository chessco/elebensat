<?php
/** Cruce separado del artículo 69 contra emisores locales. */
require_once __DIR__ . '/sat_cruce_job.php';

function sat69_crear_tabla_alertas(PDO $pdo): void
{
    // Producción: el usuario del portal NO tiene permiso CREATE.
    // Las tablas se crean una sola vez mediante el SQL de instalación.
    $st = $pdo->query("SHOW TABLES LIKE 'sat_69_alertas_emisores'");
    if (!$st || !$st->fetchColumn()) {
        throw new RuntimeException(
            "Falta la tabla sat_69_alertas_emisores. Ejecute el SQL de instalación con un usuario administrador."
        );
    }
}

function sat69_cruzar_emisores(PDO $pdo, string $jobId = '', int $idEmpresa = 0): array
{
    sat69_crear_tabla_alertas($pdo);

    /*
     * Optimización v4:
     * - Ya NO hace JOIN repetido entre cat_emisores y sat_69_contribuyentes.
     * - Lee los emisores una vez.
     * - Por bloque consulta SAT con WHERE rfc IN (...), aprovechando idx_69_rfc.
     * - Después hace UPSERT solamente de las coincidencias encontradas.
     *
     * Esto evita recorrer/joinar la tabla SAT grande decenas de veces.
     */
    if ($idEmpresa > 0) {
        $stEmisores = $pdo->prepare(
            "SELECT id_empresa, id_emisor, UPPER(TRIM(rfc)) AS rfc, nombre
               FROM cat_emisores
              WHERE id_empresa = ?
                AND TRIM(COALESCE(rfc,'')) <> ''
              ORDER BY id_emisor"
        );
        $stEmisores->execute([$idEmpresa]);
        $emisores = $stEmisores->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Compatibilidad para procesos administrativos/globales existentes.
        $emisores = $pdo->query(
            "SELECT id_empresa, id_emisor, UPPER(TRIM(rfc)) AS rfc, nombre
               FROM cat_emisores
              WHERE TRIM(COALESCE(rfc,'')) <> ''
              ORDER BY id_emisor"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $emisores = is_array($emisores) ? $emisores : [];
    $total = count($emisores);

    if ($jobId !== '') {
        sat_cruce_job_iniciar($jobId, '69', $total);
    }

    // Bloques grandes: el índice RFC del SAT resuelve cada IN muy rápido.
    $bloque = 500;
    $procesados = 0;

    // Upsert individual solamente para coincidencias; normalmente son pocas.
    $upsert = $pdo->prepare(
        "INSERT INTO sat_69_alertas_emisores
            (id_empresa,id_emisor,id_69,rfc,nombre_emisor,nombre_sat,
             tipo_publicacion,nivel_riesgo,fecha_publicacion,
             activa,fecha_primera_deteccion,fecha_ultima_deteccion)
         VALUES
            (:id_empresa,:id_emisor,:id_69,:rfc,:nombre_emisor,:nombre_sat,
             :tipo_publicacion,:nivel_riesgo,:fecha_publicacion,
             1,NOW(),NOW())
         ON DUPLICATE KEY UPDATE
            id_empresa=VALUES(id_empresa),
            rfc=VALUES(rfc),
            nombre_emisor=VALUES(nombre_emisor),
            nombre_sat=VALUES(nombre_sat),
            tipo_publicacion=VALUES(tipo_publicacion),
            nivel_riesgo=VALUES(nivel_riesgo),
            fecha_publicacion=VALUES(fecha_publicacion),
            activa=1,
            fecha_ultima_deteccion=NOW()"
    );

    try {
        $pdo->beginTransaction();

        // El cruce es atómico: si se cancela, todo vuelve al estado anterior.
        if ($idEmpresa > 0) {
            $stDesactiva = $pdo->prepare("UPDATE sat_69_alertas_emisores SET activa=0 WHERE id_empresa=?");
            $stDesactiva->execute([$idEmpresa]);
        } else {
            $pdo->exec("UPDATE sat_69_alertas_emisores SET activa=0");
        }

        foreach (array_chunk($emisores, $bloque) as $grupo) {
            if ($jobId !== '' && sat_cruce_job_cancelacion_solicitada($jobId)) {
                throw new SatCruceCanceladoException('Cruce cancelado por el usuario.');
            }

            // RFC -> lista de emisores (puede existir el mismo RFC en varias empresas)
            $porRfc = [];
            foreach ($grupo as $e) {
                $rfc = strtoupper(trim((string)$e['rfc']));
                if ($rfc === '') continue;
                if (!isset($porRfc[$rfc])) $porRfc[$rfc] = [];
                $porRfc[$rfc][] = $e;
            }

            if ($porRfc) {
                $rfcs = array_keys($porRfc);
                $marks = implode(',', array_fill(0, count($rfcs), '?'));

                // Aquí trabaja directamente idx_69_rfc.
                $st = $pdo->prepare(
                    "SELECT id_69, rfc, nombre_contribuyente,
                            tipo_publicacion, nivel_riesgo, fecha_publicacion
                       FROM sat_69_contribuyentes
                      WHERE rfc IN ($marks)"
                );
                $st->execute($rfcs);

                while ($s = $st->fetch(PDO::FETCH_ASSOC)) {
                    $rfcSat = strtoupper(trim((string)$s['rfc']));
                    if (!isset($porRfc[$rfcSat])) continue;

                    foreach ($porRfc[$rfcSat] as $e) {
                        $upsert->execute([
                            ':id_empresa'      => (int)$e['id_empresa'],
                            ':id_emisor'       => (int)$e['id_emisor'],
                            ':id_69'           => (int)$s['id_69'],
                            ':rfc'             => $rfcSat,
                            ':nombre_emisor'   => (string)$e['nombre'],
                            ':nombre_sat'      => (string)($s['nombre_contribuyente'] ?? ''),
                            ':tipo_publicacion'=> (string)($s['tipo_publicacion'] ?? ''),
                            ':nivel_riesgo'    => (string)($s['nivel_riesgo'] ?? 'INFORMATIVO'),
                            ':fecha_publicacion'=> $s['fecha_publicacion'] ?? null,
                        ]);
                    }
                }
            }

            $procesados += count($grupo);

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

        $resumen = [
            'total_emisores' => (int)$pdo->query(
                "SELECT COUNT(DISTINCT id_emisor)
                   FROM sat_69_alertas_emisores
                  WHERE activa=1" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : "")
            )->fetchColumn(),

            'emisores_riesgo' => (int)$pdo->query(
                "SELECT COUNT(DISTINCT id_emisor)
                   FROM sat_69_alertas_emisores
                  WHERE activa=1
                    AND nivel_riesgo IN ('ALTO','MEDIO')" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : "")
            )->fetchColumn(),

            'alto' => (int)$pdo->query(
                "SELECT COUNT(DISTINCT id_emisor)
                   FROM sat_69_alertas_emisores
                  WHERE activa=1
                    AND nivel_riesgo='ALTO'" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : "")
            )->fetchColumn(),

            'nuevas' => (int)$pdo->query(
                "SELECT COUNT(DISTINCT id_emisor)
                   FROM sat_69_alertas_emisores
                  WHERE activa=1
                    AND estatus_seguimiento='NUEVA'
                    AND nivel_riesgo IN ('ALTO','MEDIO')" . ($idEmpresa > 0 ? " AND id_empresa=".(int)$idEmpresa : "")
            )->fetchColumn(),
        ];

        if ($jobId !== '') {
            sat_cruce_job_finalizar(
                $jobId,
                'terminado',
                'Cruce del artículo 69 terminado.',
                ['resumen' => $resumen]
            );
        }

        return $resumen;

    } catch (SatCruceCanceladoException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($jobId !== '') {
            sat_cruce_job_finalizar(
                $jobId,
                'cancelado',
                'Cruce cancelado. No se guardaron cambios parciales.',
                [
                    'procesados' => $procesados,
                    'total' => $total,
                ]
            );
        }

        throw $e;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($jobId !== '') {
            sat_cruce_job_finalizar(
                $jobId,
                'error',
                'El cruce terminó con error.',
                ['detalle' => $e->getMessage()]
            );
        }

        throw $e;
    }
}
