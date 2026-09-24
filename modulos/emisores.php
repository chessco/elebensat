<?php
// Este módulo visual no abre sesión; evita esperar el bloqueo de sesión PHP.
?>
<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between mb-2">
        <h6 class="text-white fw-bold"><i class="bi bi-shop me-2 text-warning"></i>CATÁLOGO DE PROVEEDORES (EMISORES)</h6>
        <button class="btn btn-warning btn-sm fw-bold text-dark" onclick="nuevoEmisor()">
            <i class="bi bi-plus-circle-fill"></i> NUEVO PROVEEDOR
        </button>
    </div>

    <div class="card bg-dark border-secondary shadow">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table id="tablaEmisores" class="table table-dark table-hover mb-0" style="width:100%">
                    <thead>
                        <tr class="small text-muted text-uppercase">
                            <th>RFC</th>
                            <th>NOMBRE / RAZÓN SOCIAL</th>
                            <th>CORREO</th>
                            <th>TIPO TERCERO</th>
                            <th>OPERACIÓN</th>
                            <th>REGIÓN</th>
                            <th class="text-center">DIOT</th>
                            <th class="text-center">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEmisor" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg">
            <form id="formEmisor">
                <input type="hidden" id="id_emisor" name="id_emisor">
                <div class="modal-header py-2 border-secondary">
                    <b class="text-warning small">GESTIÓN DE PROVEEDOR</b>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-3">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="small text-muted fw-bold">RFC DEL PROVEEDOR</label>
                            <input type="text" id="rfc" name="rfc" class="form-control bg-black text-white border-secondary text-uppercase" maxlength="13" required>
                        </div>
                        <div class="col-12">
                            <label class="small text-muted fw-bold">NOMBRE O RAZÓN SOCIAL</label>
                            <input type="text" id="nombre" name="nombre" class="form-control bg-black text-white border-secondary text-uppercase" required>
                        </div>
                        <div class="col-12">
                            <label class="small text-muted fw-bold">CORREO</label>
                            <input type="email" id="correo" name="correo"
                                class="form-control bg-black text-white border-secondary"
                                maxlength="255"
                                placeholder="proveedor@empresa.com"
                                autocomplete="email">
                            <div class="text-muted mt-1" style="font-size:.7rem;">
                                Correo del proveedor sincronizado desde CONTPAQi y utilizado para avisos de complementos de pago.
                            </div>
                        </div>

                        <div class="col-12 border-top border-secondary pt-2 mt-3">
                            <span class="badge bg-info text-dark mb-2">CONFIGURACIÓN DIOT</span>
                        </div>

                        <div class="col-6">
                            <label class="small text-muted">TIPO DE TERCERO</label>
                            <select name="tipo_tercero" id="tipo_tercero" class="form-select form-select-sm bg-dark text-info border-secondary">
                                <option value="04">04 - PROVEEDOR NACIONAL</option>
                                <option value="05">05 - PROVEEDOR EXTRANJERO</option>
                                <option value="15">15 - PROVEEDOR GLOBAL</option>
                            </select>
                        </div>

                        <div class="col-6">
                            <label class="small text-muted">TIPO DE OPERACIÓN</label>
                            <select name="tipo_operacion" id="tipo_operacion" class="form-select form-select-sm bg-dark text-info border-secondary">
                                <option value="85">85 - OTROS (GENERAL)</option>
                                <option value="03">03 - SERV. PROFESIONALES</option>
                                <option value="06">06 - ARRENDAMIENTO</option>
                            </select>
                        </div>

                        <div class="col-12" id="campos_extranjero" style="display:none;">
                            <div class="row g-2 p-2 border border-info rounded bg-black bg-opacity-25">
                                <div class="col-12">
                                    <div class="small fw-bold text-info"><i class="bi bi-globe2 me-1"></i>DATOS DEL PROVEEDOR EXTRANJERO PARA DIOT</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="small text-muted">NÚMERO DE ID FISCAL</label>
                                    <input type="text" id="num_id_fiscal" name="num_id_fiscal" class="form-control form-control-sm bg-dark text-white border-secondary text-uppercase" maxlength="40">
                                </div>
                                <div class="col-md-6">
                                    <label class="small text-muted">PAÍS DE RESIDENCIA</label>
                                    <input type="text" id="pais_residencia" name="pais_residencia" class="form-control form-control-sm bg-dark text-white border-secondary text-uppercase" maxlength="3" placeholder="EJ. USA">
                                </div>
                                <div class="col-12">
                                    <label class="small text-muted">NOMBRE DEL EXTRANJERO</label>
                                    <input type="text" id="nombre_extranjero" name="nombre_extranjero" class="form-control form-control-sm bg-dark text-white border-secondary text-uppercase" maxlength="255">
                                </div>
                                <div class="col-12">
                                    <label class="small text-muted">NACIONALIDAD</label>
                                    <input type="text" id="nacionalidad" name="nacionalidad" class="form-control form-control-sm bg-dark text-white border-secondary text-uppercase" maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="col-12" id="campos_frontera">
                            <div class="row g-2 p-2 border border-warning rounded bg-black bg-opacity-25">
                                <div class="col-12">
                                    <div class="small fw-bold text-warning"><i class="bi bi-geo-alt-fill me-1"></i>CONFIGURACIÓN REGIONAL PARA DIOT</div>
                                    <div class="text-muted" style="font-size:.7rem;">La tasa real se toma del XML. Esta configuración permite distinguir frontera norte, frontera sur o resto del país.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="small text-muted">REGIÓN DIOT PREDETERMINADA</label>
                                    <select name="region_diot" id="region_diot" class="form-select form-select-sm bg-dark text-warning border-secondary">
                                        <option value="RESTO">RESTO DEL PAÍS</option>
                                        <option value="RFN">REGIÓN FRONTERIZA NORTE</option>
                                        <option value="RFS">REGIÓN FRONTERIZA SUR</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="small text-muted">TASA ESPERADA</label>
                                    <input type="text" id="tasa_iva_fronteriza" name="tasa_iva_fronteriza" class="form-control form-control-sm bg-dark text-white border-secondary text-center" value="0.080000" readonly>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" id="aplica_estimulo_fronterizo" name="aplica_estimulo_fronterizo" value="1">
                                        <label class="form-check-label small" for="aplica_estimulo_fronterizo">APLICA 8%</label>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <div class="col-12 mt-2">
                            <div class="border border-warning rounded bg-warning bg-opacity-10 p-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                        <input class="form-check-input m-0" type="checkbox" id="aplica_diferencia_base_no_objeto" name="aplica_diferencia_base_no_objeto" value="1">
                                    </div>
                                    <label class="form-check-label text-warning small fw-bold mb-0" for="aplica_diferencia_base_no_objeto">
                                        DIFERENCIA SUBTOTAL - BASE IVA A NO OBJETO
                                    </label>
                                </div>
                                <div class="text-muted small mt-1" style="font-size:0.7rem; padding-left:2.65rem;">
                                    Si existe una diferencia positiva no clasificada entre el subtotal proporcional pagado y las bases fiscales del CFDI, se informa en DIOT como No objeto. Úsalo sólo en proveedores con este criterio autorizado.
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mt-3">
                            <div class="border border-secondary rounded bg-opacity-10 bg-secondary p-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                        <input class="form-check-input m-0" type="checkbox" id="aplica_diot" name="aplica_diot" value="1" checked>
                                    </div>
                                    <label class="form-check-label text-white small fw-bold mb-0" for="aplica_diot">
                                        INCLUIR EN REPORTE DIOT
                                    </label>
                                </div>
                                <div class="text-muted small mt-1" style="font-size: 0.7rem; padding-left: 2.65rem;">
                                    Si desactivas esto, este proveedor será ignorado al generar el TXT.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1 border-secondary">
                    <button type="submit" class="btn btn-warning btn-sm fw-bold text-dark w-100">GUARDAR DATOS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// El módulo puede cargarse varias veces dentro de main.php.
// Destruimos cualquier instancia anterior antes de volver a crear DataTables.
if ($.fn.DataTable.isDataTable('#tablaEmisores')) {
    $('#tablaEmisores').DataTable().clear().destroy();
}

// Igual que el visor: inicializamos directamente al cargarse el módulo.
// main.php ya insertó el HTML antes de ejecutar este script.
    var tabla = $('#tablaEmisores').DataTable({
        destroy: true,
        processing: false,
        serverSide: true,
        deferRender: true,
        stateSave: false,
        ajax: {
            url: 'backend/emisores_operaciones.php?accion=listar',
            type: 'GET',
            cache: false,
            error: function(xhr, status, error) {
                console.error('Error catálogo emisores:', status, error, xhr.responseText);
            }
        },
        language: {
            processing: 'Procesando...',
            search: 'Buscar:',
            lengthMenu: 'Mostrar _MENU_ registros',
            info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
            infoEmpty: 'Mostrando 0 a 0 de 0 registros',
            infoFiltered: '(filtrado de _MAX_ registros totales)',
            loadingRecords: 'Cargando...',
            zeroRecords: 'No se encontraron proveedores',
            emptyTable: 'No hay proveedores registrados',
            paginate: {
                first: 'Primero',
                previous: 'Anterior',
                next: 'Siguiente',
                last: 'Último'
            }
        },
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        searchDelay: 350,
        order: [[1, 'asc']],
        dom: '<"d-flex justify-content-between align-items-center mb-2"lf>rt<"d-flex justify-content-between mt-2"ip>',
columns: [
            { data: 'rfc', className: 'fw-bold text-warning' },
            { data: 'nombre' },
            {
                data: 'correo',
                render: function(d) {
                    const correo = (d || '').trim();
                    return correo
                        ? `<span title="${$('<div>').text(correo).html()}">${$('<div>').text(correo).html()}</span>`
                        : '<span class="text-muted">Sin correo</span>';
                }
            },
            {
                data: 'tipo_tercero',
                render: function(d) {
                    return d === '04' ? 'NACIONAL' : (d === '05' ? 'EXTRANJERO' : 'GLOBAL');
                }
            },
            {
                data: 'tipo_operacion',
                render: function(d) {
                    if(d === '03') return 'HONORARIOS';
                    if(d === '06') return 'ARRENDAMIENTO';
                    return 'OTROS';
                }
            },
            {
                data: 'region_diot',
                render: function(d) {
                    if (d === 'RFN') return '<span class="badge bg-primary">RFN 8%</span>';
                    if (d === 'RFS') return '<span class="badge bg-info text-dark">RFS 8%</span>';
                    return '<span class="badge bg-secondary">RESTO</span>';
                }
            },
            {
                data: 'aplica_diot', className: 'text-center',
                render: function(d) {
                    return d == 1
                        ? '<span class="badge bg-success text-dark">SI</span>'
                        : '<span class="badge bg-danger">NO</span>';
                }
            },
            {
                data: null, className: 'text-center', orderable: false, searchable: false,
                render: function(d) {
                    let json = btoa(unescape(encodeURIComponent(JSON.stringify(d))));
                    return `
                        <button class="btn btn-outline-info btn-sm py-0 border-0" onclick="editarEmisor('${json}')"><i class="bi bi-pencil-square"></i></button>
                        <button class="btn btn-outline-danger btn-sm py-0 border-0" onclick="eliminarEmisor(${d.id_emisor})"><i class="bi bi-trash"></i></button>
                    `;
                }
            }
        ]
    });

    $('#tablaEmisores tbody').off('dblclick.emisores').on('dblclick.emisores', 'tr', function(e) {
        if ($(e.target).closest('td').index() === 7 || $(e.target).closest('button').length) {
            return;
        }

        const datos = tabla.row(this).data();
        if (!datos) return;

        const json = btoa(unescape(encodeURIComponent(JSON.stringify(datos))));
        editarEmisor(json);
    });

    $('#tablaEmisores tbody').off('mouseenter.emisores').on('mouseenter.emisores', 'tr', function() {
        $(this).css('cursor', 'pointer');
    });

    function sincronizarRegionFronteriza() {
        const region = $('#region_diot').val();
        const esFrontera = region === 'RFN' || region === 'RFS';
        $('#aplica_estimulo_fronterizo').prop('checked', esFrontera);
        $('#tasa_iva_fronteriza').val('0.080000');
    }

    $('#region_diot').off('change.emisores').on('change.emisores', sincronizarRegionFronteriza);
    $('#aplica_estimulo_fronterizo').off('change.emisores').on('change.emisores', function() {
        if (!this.checked && ($('#region_diot').val() === 'RFN' || $('#region_diot').val() === 'RFS')) {
            $('#region_diot').val('RESTO');
        }
    });

    $('#formEmisor').off('submit.emisores').on('submit.emisores', function(e) {
        e.preventDefault();
        $.post('backend/emisores_operaciones.php?accion=guardar', $(this).serialize(), function(res) {
            if(res.success) {
                $('#modalEmisor').modal('hide');
                tabla.ajax.reload(null, false);
                Swal.fire({icon:'success', title:'Guardado', timer:1000, showConfirmButton:false});
            } else {
                Swal.fire('Error', res.error, 'error');
            }
        }, 'json');
    });

    function actualizarCamposExtranjero() {
        const rfc = ($('#rfc').val() || '').toUpperCase();
        const esExtranjero = $('#tipo_tercero').val() === '05' || rfc === 'XEXX010101000';

        if (rfc === 'XEXX010101000') {
            $('#tipo_tercero').val('05');
        }

        $('#campos_extranjero').toggle(esExtranjero);
        $('#num_id_fiscal, #nombre_extranjero, #pais_residencia, #nacionalidad').prop('required', esExtranjero);
    }

    $('#tipo_tercero').off('change.emisores').on('change.emisores', actualizarCamposExtranjero);

    $('#rfc').off('input.emisores').on('input.emisores', function() {
        $(this).val($(this).val().toUpperCase().replace(/[^A-Z0-9]/g, ''));
        actualizarCamposExtranjero();
    });

    window.actualizarCamposExtranjero = actualizarCamposExtranjero;

function nuevoEmisor() {
    $('#formEmisor')[0].reset();
    $('#id_emisor').val('');
    $('#correo').val('');
    $('#tipo_tercero').val('04');
    $('#tipo_operacion').val('85');
    $('#aplica_diot').prop('checked', true);
    $('#aplica_diferencia_base_no_objeto').prop('checked', false);
    $('#region_diot').val('RESTO');
    $('#aplica_estimulo_fronterizo').prop('checked', false);
    $('#tasa_iva_fronteriza').val('0.080000');
    $('#num_id_fiscal, #nombre_extranjero, #pais_residencia, #nacionalidad').val('');
    actualizarCamposExtranjero();
    $('#modalEmisor').modal('show');
}

function editarEmisor(b64) {
    let d = JSON.parse(decodeURIComponent(escape(atob(b64))));
    $('#id_emisor').val(d.id_emisor);
    $('#rfc').val(d.rfc);
    $('#nombre').val(d.nombre);
    $('#correo').val(d.correo || '');
    $('#tipo_tercero').val(d.tipo_tercero);
    $('#tipo_operacion').val(d.tipo_operacion);
    $('#aplica_diot').prop('checked', d.aplica_diot == 1);
    $('#aplica_diferencia_base_no_objeto').prop('checked', d.aplica_diferencia_base_no_objeto == 1);
    $('#num_id_fiscal').val(d.num_id_fiscal || '');
    $('#nombre_extranjero').val(d.nombre_extranjero || '');
    $('#pais_residencia').val(d.pais_residencia || '');
    $('#nacionalidad').val(d.nacionalidad || '');
    $('#region_diot').val(d.region_diot || 'RESTO');
    $('#aplica_estimulo_fronterizo').prop('checked', d.aplica_estimulo_fronterizo == 1);
    $('#tasa_iva_fronteriza').val(d.tasa_iva_fronteriza || '0.080000');
    actualizarCamposExtranjero();
    $('#modalEmisor').modal('show');
}

function eliminarEmisor(id) {
    Swal.fire({
        title: '¿Eliminar Proveedor?',
        text: "Esto no borra sus facturas, solo lo quita del catálogo.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, borrar',
        background: '#161b22', color: '#fff'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('backend/emisores_operaciones.php?accion=eliminar', {id_emisor: id}, function(res) {
                if(res.success) {
                    $('#tablaEmisores').DataTable().ajax.reload(null, false);
                } else {
                    Swal.fire('Error', res.error, 'error');
                }
            }, 'json');
        }
    })
}
</script>
