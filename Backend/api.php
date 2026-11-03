<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/conexion_db.php';

function responder($datos, $status = 200)
{
    http_response_code($status);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function requerirSesion()
{
    if (empty($_SESSION['usuario_id'])) {
        responder(['ok' => false, 'mensaje' => 'La sesión no es válida.'], 401);
    }
}

function obtenerConexion()
{
    $hostname = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'comision_discriminadora';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $port = (int) (getenv('DB_PORT') ?: 3306);

    try {
        return (new Conexion($hostname, $dbName, $dbUser, $dbPass, $port))->conectar();
    } catch (Throwable $e) {
        responder(['ok' => false, 'mensaje' => 'No fue posible conectar con la base de datos.'], 500);
    }
}

function texto($nombre)
{
    return trim((string) ($_POST[$nombre] ?? ''));
}

function fechaValida($valor)
{
    $fecha = DateTime::createFromFormat('!Y-m-d', $valor);
    return $fecha && $fecha->format('Y-m-d') === $valor;
}

function paypalConfig()
{
    $clientId = getenv('PAYPAL_CLIENT_ID') ?: '';
    $amount = getenv('PAYPAL_AMOUNT') ?: '1.00';
    $currency = getenv('PAYPAL_CURRENCY') ?: 'USD';

    if ($clientId === '' || !preg_match('/^\d+(\.\d{2})$/', $amount)) {
        responder(['ok' => false, 'mensaje' => 'PayPal no está configurado en el servidor.'], 503);
    }

    return [
        'client_id' => $clientId,
        'amount' => $amount,
        'currency' => $currency,
    ];
}

function paypalAccessToken()
{
    $clientId = getenv('PAYPAL_CLIENT_ID') ?: '';
    $secret = getenv('PAYPAL_CLIENT_SECRET') ?: '';
    $baseUrl = (getenv('PAYPAL_MODE') ?: 'sandbox') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';

    if ($clientId === '' || $secret === '') {
        responder(['ok' => false, 'mensaje' => 'Faltan las credenciales de PayPal en el servidor.'], 503);
    }

    $curl = curl_init($baseUrl . '/v1/oauth2/token');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $clientId . ':' . $secret,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: es_MX'],
    ]);
    $respuesta = curl_exec($curl);
    $estado = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($respuesta === false || $error !== '' || $estado < 200 || $estado >= 300) {
        responder(['ok' => false, 'mensaje' => 'No fue posible autenticar con PayPal.'], 502);
    }

    $datos = json_decode($respuesta, true);
    if (empty($datos['access_token'])) {
        responder(['ok' => false, 'mensaje' => 'PayPal no devolvió un token válido.'], 502);
    }

    return [$datos['access_token'], $baseUrl];
}

function paypalRequest($metodo, $ruta, $cuerpo = null)
{
    [$token, $baseUrl] = paypalAccessToken();
    $curl = curl_init($baseUrl . $ruta);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ];
    if ($cuerpo !== null) {
        $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo);
    }
    curl_setopt_array($curl, $opciones);
    $respuesta = curl_exec($curl);
    $estado = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($respuesta === false || $error !== '') {
        responder(['ok' => false, 'mensaje' => 'No fue posible comunicarse con PayPal.'], 502);
    }
    $datos = json_decode($respuesta, true);
    if ($estado < 200 || $estado >= 300) {
        responder(['ok' => false, 'mensaje' => 'PayPal rechazó la operación.'], 502);
    }
    return $datos;
}

function pdfTexto($texto)
{
    $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $texto);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function enviarReciboPdf($proyecto)
{
    $lineas = [
        'RECIBO DE PAGO',
        'Comision Discriminadora',
        '',
        'Proyecto: ' . $proyecto['nombre_proyecto'],
        'Codigo: ' . $proyecto['codigo'],
        'Persona: ' . $proyecto['nombre_persona'],
        'Convocatoria: ' . $proyecto['convocatoria'],
        'Fecha de pago: ' . $proyecto['pagado_en'],
        'Orden PayPal: ' . $proyecto['paypal_order_id'],
        'Monto: ' . ($proyecto['monto_pago'] ?: 'Consultar') . ' ' . ($proyecto['moneda_pago'] ?: ''),
        '',
        'Estado: PAGADO',
    ];
    $contenido = "BT\n/F1 16 Tf\n";
    $y = 760;
    foreach ($lineas as $indice => $linea) {
        $contenido .= sprintf("1 0 0 1 60 %d Tm (%s) Tj\n", $y, pdfTexto($linea));
        $contenido .= $indice === 0 ? "/F1 16 Tf\n" : "/F1 11 Tf\n";
        $y -= 30;
    }
    $contenido .= "ET";

    $objetos = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objetos as $numero => $objeto) {
        $offsets[$numero + 1] = strlen($pdf);
        $pdf .= ($numero + 1) . " 0 obj\n" . $objeto . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objetos); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="recibo-pago-' . (int) $proyecto['id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$accion = $_GET['accion'] ?? '';
$conexion = null;

try {
    if ($accion === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $usuario = texto('usuario');
        $contrasena = (string) ($_POST['contrasena'] ?? '');

        if ($usuario === '' || $contrasena === '') {
            responder(['ok' => false, 'mensaje' => 'Usuario y contraseña son obligatorios.'], 422);
        }

        $conexion = obtenerConexion();
        $stmt = mysqli_prepare(
            $conexion,
            'SELECT id, nombre_completo, nombre_usuario, correo, contrasena_hash
             FROM usuarios WHERE nombre_usuario = ? AND activo = TRUE LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 's', $usuario);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);
        $registro = mysqli_fetch_assoc($resultado);
        mysqli_stmt_close($stmt);

        if (!$registro || !password_verify($contrasena, $registro['contrasena_hash'])) {
            responder(['ok' => false, 'mensaje' => 'Usuario o contraseña incorrectos.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int) $registro['id'];
        $_SESSION['nombre_completo'] = $registro['nombre_completo'];
        $_SESSION['nombre_usuario'] = $registro['nombre_usuario'];
        $_SESSION['correo'] = $registro['correo'];

        responder([
            'ok' => true,
            'usuario' => [
                'id' => $_SESSION['usuario_id'],
                'nombre_completo' => $_SESSION['nombre_completo'],
                'nombre_usuario' => $_SESSION['nombre_usuario'],
            ],
        ]);
    }

    if ($accion === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
        }
        session_destroy();
        responder(['ok' => true]);
    }

    requerirSesion();
    $conexion = obtenerConexion();

    if ($accion === 'sesion') {
        responder([
            'ok' => true,
            'usuario' => [
                'id' => $_SESSION['usuario_id'],
                'nombre_completo' => $_SESSION['nombre_completo'],
                'nombre_usuario' => $_SESSION['nombre_usuario'],
            ],
        ]);
    }

    if ($accion === 'paypal-config') {
        responder(['ok' => true, 'paypal' => paypalConfig()]);
    }

    if ($accion === 'paypal-crear-orden' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $convocatoriaId = filter_var($_POST['convocatoria_id'] ?? null, FILTER_VALIDATE_INT);
        $config = paypalConfig();
        if (!$convocatoriaId) {
            responder(['ok' => false, 'mensaje' => 'Convocatoria inválida.'], 422);
        }

        $orden = paypalRequest('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $convocatoriaId,
                'custom_id' => (string) $_SESSION['usuario_id'],
                'amount' => [
                    'currency_code' => $config['currency'],
                    'value' => $config['amount'],
                ],
            ]],
        ]);
        if (empty($orden['id'])) {
            responder(['ok' => false, 'mensaje' => 'PayPal no creó la orden.'], 502);
        }
        $_SESSION['paypal_orders'][$orden['id']] = $convocatoriaId;
        responder(['ok' => true, 'order_id' => $orden['id']]);
    }

    if ($accion === 'paypal-capturar-orden' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $orderId = texto('order_id');
        $convocatoriaId = filter_var($_POST['convocatoria_id'] ?? null, FILTER_VALIDATE_INT);
        $ordenes = $_SESSION['paypal_orders'] ?? [];
        if ($orderId === '' || !$convocatoriaId || ($ordenes[$orderId] ?? null) !== $convocatoriaId) {
            responder(['ok' => false, 'mensaje' => 'La orden de PayPal no es válida para esta convocatoria.'], 422);
        }

        $captura = paypalRequest('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');
        if (($captura['status'] ?? '') !== 'COMPLETED') {
            responder(['ok' => false, 'mensaje' => 'El pago de PayPal no fue completado.'], 402);
        }
        $_SESSION['paypal_orders'][$orderId] = [
            'convocatoria_id' => $convocatoriaId,
            'capturado' => true,
        ];
        responder(['ok' => true, 'order_id' => $orderId]);
    }

    if ($accion === 'convocatorias') {
        $resultado = mysqli_query(
            $conexion,
            'SELECT c.id, c.nombre, c.tipo, c.fecha_inicio, c.fecha_fin, c.clave_registro,
                    c.creada_en, COALESCE(u.nombre_completo, "Sin responsable") AS responsable
             FROM convocatorias c
             LEFT JOIN usuarios u ON u.id = c.responsable_id
             ORDER BY c.creada_en DESC'
        );
        $convocatorias = [];
        while ($fila = mysqli_fetch_assoc($resultado)) {
            $convocatorias[] = $fila;
        }
        responder(['ok' => true, 'convocatorias' => $convocatorias]);
    }

    if ($accion === 'convocatoria') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            responder(['ok' => false, 'mensaje' => 'Convocatoria inválida.'], 422);
        }

        $stmt = mysqli_prepare(
            $conexion,
            'SELECT c.id, c.nombre, c.tipo, c.fecha_inicio, c.fecha_fin, c.clave_registro,
                    c.creada_en, COALESCE(u.nombre_completo, "Sin responsable") AS responsable
             FROM convocatorias c
             LEFT JOIN usuarios u ON u.id = c.responsable_id
             WHERE c.id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $convocatoria = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$convocatoria) {
            responder(['ok' => false, 'mensaje' => 'Convocatoria no encontrada.'], 404);
        }

        $stmt = mysqli_prepare(
            $conexion,
            'SELECT id, nombre_persona, codigo, fecha_ingreso, nombre_proyecto, fecha_terminado,
                    pago_estado
             FROM proyectos WHERE convocatoria_id = ? ORDER BY fecha_ingreso, id'
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);
        $proyectos = [];
        while ($fila = mysqli_fetch_assoc($resultado)) {
            $proyectos[] = $fila;
        }
        mysqli_stmt_close($stmt);

        responder(['ok' => true, 'convocatoria' => $convocatoria, 'proyectos' => $proyectos]);
    }

    if ($accion === 'recibo') {
        $proyectoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$proyectoId) {
            responder(['ok' => false, 'mensaje' => 'Proyecto inválido.'], 422);
        }
        $stmt = mysqli_prepare(
            $conexion,
            'SELECT p.id, p.nombre_proyecto, p.codigo, p.nombre_persona, p.pago_estado,
                    p.paypal_order_id, p.pagado_en, c.nombre AS convocatoria,
                    ? AS monto_pago, ? AS moneda_pago
             FROM proyectos p
             JOIN convocatorias c ON c.id = p.convocatoria_id
             WHERE p.id = ?'
        );
        $monto = getenv('PAYPAL_AMOUNT') ?: '1.00';
        $moneda = getenv('PAYPAL_CURRENCY') ?: 'USD';
        mysqli_stmt_bind_param($stmt, 'ssi', $monto, $moneda, $proyectoId);
        mysqli_stmt_execute($stmt);
        $proyecto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$proyecto || $proyecto['pago_estado'] !== 'pagado') {
            responder(['ok' => false, 'mensaje' => 'El recibo solo está disponible para pagos completados.'], 404);
        }
        enviarReciboPdf($proyecto);
    }

    if ($accion === 'crear-convocatoria' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre = texto('nombre');
        $tipo = texto('tipo');
        $inicio = texto('inicio');
        $fin = texto('fin');
        $clave = texto('clave');
        $tiposValidos = ['Académica', 'Investigación', 'Servicio institucional'];

        if ($nombre === '' || !in_array($tipo, $tiposValidos, true) || $inicio === '' || $fin === '' || $clave === '') {
            responder(['ok' => false, 'mensaje' => 'Completa todos los campos correctamente.'], 422);
        }
        if ($fin < $inicio) {
            responder(['ok' => false, 'mensaje' => 'La fecha final no puede ser anterior a la inicial.'], 422);
        }

        $stmt = mysqli_prepare(
            $conexion,
            'INSERT INTO convocatorias
             (nombre, tipo, fecha_inicio, fecha_fin, clave_registro, responsable_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $responsable = (int) $_SESSION['usuario_id'];
        mysqli_stmt_bind_param($stmt, 'sssssi', $nombre, $tipo, $inicio, $fin, $clave, $responsable);
        $ok = mysqli_stmt_execute($stmt);
        $id = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            responder(['ok' => false, 'mensaje' => 'No fue posible crear la convocatoria.'], 500);
        }
        responder(['ok' => true, 'id' => $id]);
    }

    if ($accion === 'crear-proyecto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $convocatoriaId = filter_var($_POST['convocatoria_id'] ?? null, FILTER_VALIDATE_INT);
        $nombrePersona = texto('nombre_persona');
        $codigo = texto('codigo');
        $fechaIngreso = texto('fecha_ingreso');
        $nombreProyecto = texto('nombre_proyecto');
        $fechaTerminado = texto('fecha_terminado');
        $paypalOrderId = texto('paypal_order_id');
        $ordenPagada = $_SESSION['paypal_orders'][$paypalOrderId] ?? null;

        if (
            !$convocatoriaId || $nombrePersona === '' || $codigo === '' ||
            $fechaIngreso === '' || $nombreProyecto === '' || $paypalOrderId === ''
        ) {
            responder(['ok' => false, 'mensaje' => 'Completa los datos y realiza el pago con PayPal.'], 422);
        }
        if (
            !is_array($ordenPagada) ||
            empty($ordenPagada['capturado']) ||
            $ordenPagada['convocatoria_id'] !== $convocatoriaId
        ) {
            responder(['ok' => false, 'mensaje' => 'Debes completar el pago antes de guardar el proyecto.'], 402);
        }
        if (!fechaValida($fechaIngreso) || ($fechaTerminado !== '' && !fechaValida($fechaTerminado))) {
            responder(['ok' => false, 'mensaje' => 'Las fechas del proyecto no son válidas.'], 422);
        }

        $stmt = mysqli_prepare(
            $conexion,
            'SELECT fecha_inicio, fecha_fin FROM convocatorias WHERE id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'i', $convocatoriaId);
        mysqli_stmt_execute($stmt);
        $convocatoria = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$convocatoria) {
            responder(['ok' => false, 'mensaje' => 'La convocatoria no existe.'], 404);
        }
        if ($fechaIngreso < $convocatoria['fecha_inicio'] || $fechaIngreso > $convocatoria['fecha_fin']) {
            responder(['ok' => false, 'mensaje' => 'La fecha de ingreso debe estar dentro del periodo de la convocatoria.'], 422);
        }
        if ($fechaTerminado !== '' && $fechaTerminado < $fechaIngreso) {
            responder(['ok' => false, 'mensaje' => 'La fecha de terminado no puede ser anterior a la fecha de ingreso.'], 422);
        }

        $stmt = mysqli_prepare(
            $conexion,
            'INSERT INTO proyectos
             (convocatoria_id, nombre_persona, codigo, fecha_ingreso, nombre_proyecto, fecha_terminado,
              pago_estado, paypal_order_id, pagado_en)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, ""), "pagado", ?, NOW())'
        );
        mysqli_stmt_bind_param(
            $stmt,
            'issssss',
            $convocatoriaId,
            $nombrePersona,
            $codigo,
            $fechaIngreso,
            $nombreProyecto,
            $fechaTerminado,
            $paypalOrderId
        );
        $ok = mysqli_stmt_execute($stmt);
        $errorMysql = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            $mensaje = $errorMysql === 1062
                ? 'El código del proyecto ya está registrado.'
                : 'No fue posible crear el proyecto.';
            responder(['ok' => false, 'mensaje' => $mensaje], $errorMysql === 1062 ? 409 : 500);
        }
        responder(['ok' => true, 'id' => mysqli_insert_id($conexion)]);
    }

    if ($accion === 'exportar') {
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="convocatorias.csv"');
        $salida = fopen('php://output', 'w');
        fputcsv($salida, ['Nombre', 'Tipo', 'Fecha inicio', 'Fecha fin', 'Clave', 'Responsable']);
        $resultado = mysqli_query(
            $conexion,
            'SELECT c.nombre, c.tipo, c.fecha_inicio, c.fecha_fin, c.clave_registro,
                    COALESCE(u.nombre_completo, "Sin responsable")
             FROM convocatorias c LEFT JOIN usuarios u ON u.id = c.responsable_id
             ORDER BY c.creada_en DESC'
        );
        while ($fila = mysqli_fetch_row($resultado)) {
            fputcsv($salida, $fila);
        }
        fclose($salida);
        exit;
    }

    responder(['ok' => false, 'mensaje' => 'Acción no encontrada.'], 404);
} catch (Throwable $e) {
    responder(['ok' => false, 'mensaje' => 'Ocurrió un error al procesar la solicitud.'], 500);
} finally {
    if ($conexion) {
        mysqli_close($conexion);
    }
}
