// Prueba de lógica con dobles DOM; no sustituye revisión visual en navegador.
import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../includes/nomina_config_campos_ui.js',import.meta.url),'utf8');
const els={},responses=[],calls=[];let count=0;
function ck(value,msg){count++;assert.ok(value,msg);}
class Element{
 constructor(id=''){this.id=id;this.value='';this.disabled=false;this.hidden=false;this.listeners={};this.files=[];this.options=[];this.rows=[];}
 set innerHTML(html){this.html=html;for(const m of html.matchAll(/<([a-z]+)\b([^>]*\bid="([^"]+)"[^>]*)>/g)){const e=els[m[3]]=new Element(m[3]);e.value=/\bvalue="([^"]*)"/.exec(m[2])?.[1]||'';e.disabled=/\bdisabled\b/.test(m[2]);e.hidden=/\bhidden\b/.test(m[2]);}
  this.options=[...html.matchAll(/<option value="([^"]*)"([^>]*)>(.*?)<\/option>/g)].map(m=>({value:m[1],selected:m[2].includes('selected'),textContent:m[3]}));
  if(this.options.length){if(!this.options.some(o=>o.selected))this.options[0].selected=true;this.value=this.options.find(o=>o.selected).value;}
  if(this.id==='ncmTerminos')this.rows=html.split('<div data-term=').slice(1).map(chunk=>{const row=new Element();row.selects={};for(const m of chunk.matchAll(/<select class="([^" ]+)[^"]*"[^>]*>(.*?)<\/select>/g)){const el=new Element();el.innerHTML=m[2];row.selects['.'+m[1]]=el;}return row;});
 }
 get innerHTML(){return this.html||'';}get selectedOptions(){return this.options.filter(o=>o.selected);}
 querySelector(s){return s.startsWith('#')?els[s.slice(1)]:this.selects?.[s];}querySelectorAll(){return this.rows;}addEventListener(n,fn){this.listeners[n]=fn;}
}
const host=els['nomina-config-campos']=new Element('nomina-config-campos'),tab=new Element();
const ctx={URLSearchParams,FormData,window:{nominaCamposCsrf:'test_csrf',confirm:()=>true},document:{getElementById:id=>els[id]||null,querySelector:()=>tab},fetch:async(url,opt)=>{const params=Object.fromEntries(opt.body||new URLSearchParams(url.split('?')[1]));calls.push(params);if(opt.method==='POST')ck(params.csrf==='test_csrf','CSRF included');const r=responses.shift();assert.ok(r,'Unexpected '+params.accion);return {ok:r.success!==false,json:async()=>JSON.parse(JSON.stringify(r))};}};
vm.runInNewContext(source,ctx);
const field={id_campo:1,encabezado:'P777 - Nuevo <script>',clave_campo:'CODIGO:P777',tipo_valor:'TEXTO',configuracion:null,plan:{modo:'pendiente',regla:'Sin relación'}};
const xml={id_xml_campo:10,etiqueta:'Nomina/Percepcion[Clave=P777]@ImporteGravado',ejemplos:['10 <script>'],id_muestra:1};
const sample={id_muestra:1,nombre_archivo:'normal.xml',rfc_receptor:'TEST',fecha_pago:'2026-07-04'};
const listing=()=>({success:true,campos:[field],campos_xml:[xml],muestras:[sample]});
responses.push(listing());await els.ncmActualizar.onclick();ck(els.ncmCampos.innerHTML.includes('&lt;script&gt;'),'Escape heading');
els.ncmCampos.onclick({target:{closest:()=>({dataset:{campo:1}})}});ck(!els.ncmEditor.hidden,'Open editor');ck(els.ncmEstado.value==='PREDETERMINADO','Default preserved');
els.ncmEstado.value='RELACIONADO';els.ncmEstado.onchange();els.ncmOperacion.value='SUMA';els.ncmOperacion.onchange();els.ncmTerminos.rows[0].selects['.ncmXmlSelect'].value='10';els.ncmTerminos.onchange();ck(els.ncmTerminos.innerHTML.includes('&lt;script&gt;'),'Escape XML example');
const probe={success:true,prueba:{apta:true,aviso:'Revisado',muestras:[{archivo:'normal.xml',rfc:'TEST',valor:10,faltan:0,errores:[]}]}};
responses.push(probe);await els.ncmProbar.onclick();ck(!els.ncmGuardar.disabled,'Save only after proof');els.ncmAusencia.value='CERO';els.ncmAusencia.onchange();ck(els.ncmGuardar.disabled,'Changed absence invalidates proof');
responses.push(probe);await els.ncmProbar.onclick();els.ncmComentario.value='Prueba';field.configuracion={estado:'RELACIONADO',tipo_workbeat:'NUMERO',operacion:'SUMA',ausencia:'CERO',terminos:[{id_xml_campo:10,signo:1}],revision:1};field.plan={modo:'configurado',regla:'Configurada v1'};responses.push({success:true,mensaje:'Guardada'},listing());await els.ncmGuardar.onclick();ck(calls.find(x=>x.accion==='guardar').version==='0','Expected version submitted');ck(els.ncmGuardar.disabled,'Save reset after reload');
// Refresh during XML upload must populate the editor despite busy=true.
sample.nombre_archivo='muestra_nueva.xml';els.ncmXml.files=[new Blob(['<xml/>'])];responses.push({success:true,aviso:'Leído',resultados:[{archivo:'muestra_nueva.xml',success:true,campos_detectados:5,campos_nuevos:1}]},listing());await els.ncmLeerXml.onclick();ck(els.ncmMuestras.innerHTML.includes('muestra_nueva.xml'),'Editor refreshed after XML upload');
els.ncmEstado.value='INFORMATIVO';els.ncmEstado.onchange();ck(els.ncmRelacion.hidden,'Informative requires no XML selector');responses.push({success:true,prueba:{apta:true,aviso:'Sin comparación',muestras:[]}});await els.ncmProbar.onclick();ck(!els.ncmGuardar.disabled,'Informative can be saved');
els.ncmEstado.value='RELACIONADO';els.ncmOperacion.value='TEXTO';els.ncmEstado.onchange();ck(els.ncmAusencia.value==='PENDIENTE'&&els.ncmAusencia.disabled,'Text never assumes zero');ck(els.ncmTerminos.rows[0].selects['.ncmSign'].value==='1','Text positive single term');
responses.push({success:false,error:'La sesión cambió'});await els.ncmActualizar.onclick();ck(els.ncmAviso.textContent==='La sesión cambió','Visible error');ck(responses.length===0,'All expected requests consumed');
// La tabla muestra el nodo efectivo sin abrir cada editor.
const catalog=[];
function row(id,header,plan,configuration=null){return {id_campo:id,encabezado:header,clave_campo:'TEST:'+id,tipo_valor:'TEXTO',plan,configuracion:configuration};}
catalog.push(row(1,'Empleado',{modo:'atributo',atributo:'NumEmpleado'}),row(2,'RFC',{modo:'atributo',atributo:'Rfc'}),row(3,'Ejercicio',{modo:'atributo',atributo:'Ejercicio'}));
for(const [i,grupo,codigo,tipo] of [[4,'Percepcion','P002','001'],[5,'Deduccion','D016','002'],[6,'OtroPago','P065','999']])catalog.push(row(i,codigo,{modo:'concepto',grupo,codigo,tipo}));
for(const [i,codigo] of [[7,'TOTALPER'],[8,'TOTALDED'],[9,'NETO']])catalog.push(row(i,codigo,{modo:'total',codigo}));
catalog.push(row(10,'Cuota interna',{modo:'informativo'},{estado:'INFORMATIVO',revision:2}),row(11,'Nuevo pendiente',{modo:'pendiente',regla:'Revisar'}));
const custom={estado:'RELACIONADO',revision:3,operacion:'SUMA',ausencia:'PENDIENTE',terminos:[{etiqueta:'Nomina/Percepcion[Clave=P777]@ImporteGravado',signo:1},{etiqueta:'Nomina/Percepcion[Clave=P777]@ImporteExento',signo:1},{etiqueta:'Nomina/Deduccion[Clave=D777]@Importe <script>',signo:-1}]};
catalog.push(row(12,'Personalizado',{modo:'configurado',definicion:custom},custom));
catalog.push(row(13,'Restaurado',{modo:'atributo',atributo:'Curp'},{estado:'PREDETERMINADO',revision:4,terminos:[{etiqueta:'RELACION_ANTIGUA',signo:1}]}));
responses.push({success:true,campos:catalog,campos_xml:[xml],muestras:[sample]});await els.ncmActualizar.onclick();
const table=els.ncmCampos.innerHTML;
ck(host.innerHTML.includes('Nodo / campo XML ligado'),'New column in catalog');
for(const path of ['Comprobante/Complemento/Nomina/Receptor@NumEmpleado','Comprobante/Receptor@Rfc','AÑO(Comprobante/Complemento/Nomina@FechaPago)','Percepciones/Percepcion[Clave=P002, TipoPercepcion=001]@ImporteGravado','Percepciones/Percepcion[Clave=P002, TipoPercepcion=001]@ImporteExento','Deducciones/Deduccion[Clave=D016, TipoDeduccion=002]@Importe','OtrosPagos/OtroPago[Clave=P065, TipoOtroPago=999]@Importe','Nomina@TotalPercepciones','Nomina@TotalOtrosPagos','Nomina@TotalDeducciones','Comprobante@Total'])ck(table.includes(path),'Actual default path: '+path);
ck(table.includes('No disponible en XML — informativo')&&table.includes('Sin relación XML definida'),'Unmapped and informative explicit');ck(table.includes('+ Nomina/Percepcion[Clave=P777]@ImporteExento')&&table.includes('− Nomina/Deduccion[Clave=D777]@Importe &lt;script&gt;'),'Saved multi term relation, signs and escaping');ck(table.includes('Versión 3'),'Saved revision shown');ck(table.includes('Nomina/Receptor@Curp')&&!table.includes('RELACION_ANTIGUA'),'Reset shows effective default, not old mapping');
els.ncmBuscar.value='NumEmpleado';els.ncmBuscar.oninput();ck(els.ncmConteo.textContent.startsWith('1 de 13'),'Search by XML node');
els.ncmBuscar.value='';els.ncmFiltro.value='RELACIONADO';els.ncmFiltro.onchange();ck(els.ncmConteo.textContent.startsWith('11 de 13'),'Related filter includes defaults and custom only');
els.ncmFiltro.value='PENDIENTE';els.ncmFiltro.onchange();ck(els.ncmConteo.textContent.startsWith('1 de 13')&&els.ncmCampos.innerHTML.includes('Nuevo pendiente'),'Pending filter preserved');
els.ncmBuscar.value='no-existe';els.ncmBuscar.oninput();ck(els.ncmCampos.innerHTML.includes('colspan="3"'),'Empty row spans all three columns');
ck(responses.length===0,'All table refresh requests consumed');
console.log(`OK: ${count} comprobaciones UI de relaciones visibles, rutas, búsqueda, prueba/guardado y errores (dobles DOM).`);
