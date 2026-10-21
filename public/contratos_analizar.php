<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../config/contratos_ai.php';

function contratoEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = '';
$result = null;
$filename = '';
$csrf = Csrf::token();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST) && empty($_FILES)) {
            throw new RuntimeException('No se recibió la carga. El PDF puede superar post_max_size del servidor.');
        }
        if (!is_string($_POST['csrf'] ?? null) || !Csrf::validate($_POST['csrf'])) {
            throw new RuntimeException('La sesión del formulario expiró. Recarga la página e inténtalo nuevamente.');
        }
        session_write_close();
        @set_time_limit(180);
        $file = $_FILES['pdf'] ?? [];
        $result = contratosAnalizar(is_array($file) ? $file : []);
        $filename = basename((string)$file['name']);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'No se pudo procesar el contrato. Revisa la configuración del servidor e intenta nuevamente.';
    }
}
$json = $result === null ? '' : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analizador de contratos | CEONext</title>
    <style>
        :root{--ink:#203832;--muted:#65736c;--paper:#f5f3ec;--line:#d9ded5;--accent:#cbe67c}
        *{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:"Avenir Next","Segoe UI",sans-serif;line-height:1.6}
        header{border-bottom:1px solid var(--line);padding:20px 5%;display:flex;justify-content:space-between;gap:20px;align-items:center}header strong{letter-spacing:.04em}header span,.eyebrow{font-size:.75rem;letter-spacing:.15em;text-transform:uppercase}
        main{max-width:1220px;margin:auto;padding:48px 28px 70px}.intro{display:grid;grid-template-columns:1.4fr 1fr;align-items:end;gap:36px;margin-bottom:36px}h1{font-family:Georgia,serif;font-weight:400;font-size:clamp(2.5rem,5vw,4.5rem);line-height:1.08;letter-spacing:-.04em;margin:12px 0}h2{font-size:1.2rem;margin:0 0 16px}h3{font-size:1rem;margin:0 0 8px}p{margin:0 0 16px}.muted{color:var(--muted)}
        .workspace{display:grid;grid-template-columns:320px minmax(0,1fr);gap:28px;align-items:start}.upload{background:var(--ink);color:white;padding:28px;border-radius:4px}.upload .muted{color:#c0cfc6}.upload label{display:block;margin:24px 0 12px;font-weight:600}input[type=file]{width:100%;font:inherit;font-size:.85rem;border:1px dashed #8b9d93;padding:18px 12px;border-radius:4px}input::file-selector-button{background:#e9eee7;border:0;padding:8px;cursor:pointer;margin-bottom:10px;color:var(--ink)}button,.button{font:inherit;font-weight:600;border:1px solid var(--ink);border-radius:3px;padding:11px 18px;cursor:pointer;background:var(--ink);color:white;text-decoration:none;display:inline-block}button:disabled{opacity:.6;cursor:wait}.upload button{width:100%;background:var(--accent);color:var(--ink);border:0;margin:20px 0 12px}small{font-size:.8rem}#progress{margin-top:16px}.panel{background:#fffefb;border:1px solid var(--line);padding:28px;margin-bottom:20px;border-radius:4px}.empty{min-height:390px;display:flex;justify-content:center;flex-direction:column;padding:44px}.empty .number{font:4rem Georgia,serif;color:#a3afa0}.steps{display:flex;gap:24px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:22px;margin-top:20px;font-size:.85rem}.error{background:#fff0ea;border:1px solid #d8a18c;padding:18px;margin-bottom:24px;color:#8b3524}.notice{border-left:4px solid #bd922f}.summary{background:#eaf0dd}.tag{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700}.file-name{overflow-wrap:anywhere}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.section-head span{color:#86947e;font-family:Georgia,serif;font-size:1.6rem}dl{margin:0}.field{display:grid;grid-template-columns:180px minmax(0,1fr);gap:20px;padding:13px 0;border-top:1px solid var(--line)}dt{font-size:.85rem;color:var(--muted)}dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}dd small{display:block;margin-top:5px;color:var(--muted)}.item+.item{border-top:1px solid var(--line);padding-top:20px;margin-top:20px}.item p{white-space:pre-wrap;overflow-wrap:anywhere}.tools{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.secondary{background:transparent;color:var(--ink)}summary{cursor:pointer;font-weight:600}pre{background:#1b2d28;color:#e2eddd;white-space:pre-wrap;overflow-wrap:anywhere;padding:22px;max-height:500px;overflow:auto;font-size:.8rem}:focus-visible{outline:3px solid #a9bf50;outline-offset:4px}[hidden]{display:none!important}
        @media(max-width:800px){.intro,.workspace{grid-template-columns:1fr}.intro{gap:12px}main{padding:28px 18px}.field{grid-template-columns:1fr;gap:4px}.panel,.empty{padding:24px}header span{letter-spacing:.04em}.empty{min-height:280px}}
    </style>
</head>
<body>
<header><strong>CEO<span style="text-transform:none">Next</span></strong><span>Documentos / Contratos de trabajo</span></header>
<main>
    <div class="intro">
        <div><div class="eyebrow">Lectura documental · OpenAI</div><h1>Tu contrato,<br>punto por punto.</h1></div>
        <p class="muted">Transforma un contrato en PDF en información organizada: partes, jornada, remuneración y cláusulas relevantes, con referencias al documento.</p>
    </div>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= contratoEsc($error) ?></div><?php endif; ?>
    <div class="workspace">
        <aside class="upload">
            <div class="eyebrow">01 / Documento</div><h2>Analizar un contrato</h2>
            <p class="muted">Selecciona el contrato completo, incluidos sus anexos si corresponde.</p>
            <form method="post" enctype="multipart/form-data" id="contract-form">
                <input type="hidden" name="csrf" value="<?= contratoEsc($csrf) ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= CONTRATOS_PDF_MAX_BYTES ?>">
                <label for="pdf">Archivo PDF</label>
                <input type="file" id="pdf" name="pdf" accept=".pdf,application/pdf" required aria-describedby="file-help">
                <small id="file-help" class="muted">Máximo 15 MB, sujeto al límite del servidor. Sin contraseña.</small>
                <button type="submit" id="analyze">Analizar contrato →</button>
                <small class="muted">El PDF se envía a OpenAI para realizar el análisis. Esta página no guarda un historial.</small>
                <div id="progress" role="status" hidden>Analizando el documento… Esto puede tardar hasta unos minutos.</div>
            </form>
        </aside>
        <div id="results">
        <?php if ($result === null): ?>
            <section class="panel empty">
                <div class="number" aria-hidden="true">Aa.</div>
                <h2>Los detalles importantes, en un solo lugar.</h2>
                <p class="muted">El resultado aparecerá aquí. Cada dato incluirá su referencia cuando pueda identificarse; los datos ausentes se mostrarán como «No indicado».</p>
                <div class="steps"><span>01 &nbsp; Sube el PDF</span><span>02 &nbsp; Revisa los datos</span><span>03 &nbsp; Descarga el JSON</span></div>
            </section>
        <?php else: ?>
            <section class="panel summary <?= !$result['es_contrato_laboral'] ? 'notice' : '' ?>">
                <div class="tag"><?= $result['es_contrato_laboral'] ? '02 / Análisis disponible' : 'Documento no identificado como contrato laboral' ?></div>
                <h2 class="file-name"><?= contratoEsc($filename) ?></h2>
                <p><?= contratoEsc($result['resumen']) ?></p>
                <small class="muted">Extracción asistida por IA. Revisa los datos y referencias contra el documento original.</small>
            </section>
            <?php if ($result['es_contrato_laboral']): ?>
                <?php $sectionNumber = 0; foreach (contratosCampos() as $key => $section): ?>
                <section class="panel">
                    <div class="section-head"><h2><?= contratoEsc($section['titulo']) ?></h2><span><?= sprintf('%02d', ++$sectionNumber) ?></span></div>
                    <dl><?php foreach ($section['campos'] as $field => $label): $dato = $result[$key][$field]; ?>
                        <div class="field"><dt><?= contratoEsc($label) ?></dt><dd><?= contratoEsc($dato['valor'] ?? 'No indicado') ?><?php if ($dato['referencia'] !== null): ?><small><?= contratoEsc($dato['referencia']) ?></small><?php endif; ?></dd></div>
                    <?php endforeach; ?></dl>
                </section>
                <?php endforeach; ?>
                <section class="panel"><h2>Cláusulas relevantes</h2>
                    <?php if (!$result['clausulas_relevantes']): ?><p class="muted">No se identificaron cláusulas adicionales.</p><?php endif; ?>
                    <?php foreach ($result['clausulas_relevantes'] as $item): ?>
                        <article class="item"><h3><?= contratoEsc($item['titulo']) ?></h3><p><?= contratoEsc($item['detalle']) ?></p><small class="muted"><?= contratoEsc($item['referencia'] ?? 'Referencia no identificada') ?></small></article>
                    <?php endforeach; ?>
                </section>
                <section class="panel"><h2>Observaciones para revisar</h2>
                    <?php if (!$result['observaciones']): ?><p class="muted">No se identificaron observaciones en el análisis.</p><?php endif; ?>
                    <?php foreach ($result['observaciones'] as $item): ?>
                        <article class="item"><div class="tag"><?= contratoEsc(str_replace('_', ' ', $item['tipo'])) ?></div><p><?= contratoEsc($item['detalle']) ?></p><small class="muted"><?= contratoEsc($item['referencia'] ?? 'Referencia no identificada') ?></small></article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
            <section class="panel"><h2>03 / Datos estructurados</h2><p class="muted">El mismo JSON que se utilizó para mostrar este análisis.</p>
                <div class="tools"><button type="button" id="download-json">Descargar JSON</button><button type="button" class="secondary" id="copy-json">Copiar JSON</button></div>
                <p id="json-status" role="status"></p>
                <details><summary>Ver JSON completo</summary><pre id="json-output"><?= contratoEsc((string)$json) ?></pre></details>
            </section>
        <?php endif; ?>
        </div>
    </div>
</main>
<script>
const form = document.getElementById('contract-form');
const input = document.getElementById('pdf');
input.addEventListener('change', () => {
    input.setCustomValidity(input.files[0] && input.files[0].size > <?= CONTRATOS_PDF_MAX_BYTES ?> ? 'El PDF debe pesar como máximo 15 MB.' : '');
});
form.addEventListener('submit', () => {
    document.getElementById('analyze').disabled = true;
    document.getElementById('progress').hidden = false;
    form.setAttribute('aria-busy', 'true');
});
window.addEventListener('pageshow', () => {
    document.getElementById('analyze').disabled = false;
    document.getElementById('progress').hidden = true;
    form.removeAttribute('aria-busy');
});
const output = document.getElementById('json-output');
if (output) {
    document.getElementById('download-json').addEventListener('click', () => {
        const url = URL.createObjectURL(new Blob([output.textContent], {type: 'application/json;charset=utf-8'}));
        const link = document.createElement('a');
        link.href = url; link.download = 'analisis-contrato.json';
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    document.getElementById('copy-json').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(output.textContent);
            document.getElementById('json-status').textContent = 'JSON copiado.';
        } catch (error) {
            output.parentElement.open = true;
            const range = document.createRange(); range.selectNodeContents(output);
            const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
            document.getElementById('json-status').textContent = 'Usa Ctrl+C o Cmd+C para copiar el JSON seleccionado.';
        }
    });
}
</script>
</body>
</html>
