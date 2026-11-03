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
            'SELECT nombre_persona, codigo, fecha_ingreso, nombre_proyecto, fecha_terminado
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
