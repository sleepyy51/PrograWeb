<?php
class Conexion
{
    private $hostname;
    private $db_name;
    private $db_user;
    private $db_pass;
    private $puerto;

    function __construct($hostname, $db_name, $user, $db_pass, $puerto)
    {
        $this->hostname = $hostname;
        $this->db_name = $db_name;
        $this->db_user = $user;
        $this->db_pass = $db_pass;
        $this->puerto = $puerto;
    }

    function conectar()
    {
        $conexion = mysqli_connect(
            $this->hostname,
            $this->db_user,
            $this->db_pass,
            $this->db_name,
            $this->puerto
        );

        if (!$conexion) {
            die("Error de conexión: " . mysqli_connect_error());
        }
        mysqli_set_charset($conexion, "utf8mb4");
        return $conexion;
    }

    function desconectar($conexion)
    {
        return mysqli_close($conexion);
    }

    function buscar($tabla, $conexion)
    {
        $query = mysqli_query($conexion, "SELECT * FROM `$tabla`");
        return $query;
    }

    function insertarUsuario($nombre_completo, $nombre_usuario, $correo, $contrasena, $conexion)
    {
        $sql = "INSERT INTO usuarios (nombre_completo, nombre_usuario, correo, contrasena_hash) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sql);
        $hash = password_hash($contrasena, PASSWORD_DEFAULT);

        mysqli_stmt_bind_param($stmt, "ssss", $nombre_completo, $nombre_usuario, $correo, $hash);
        $ok = mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);
        return $ok;
    }

    function borrarUsuario($nombre_usuario, $conexion)
    {
        $sql = "DELETE FROM usuarios WHERE nombre_usuario = ?";
        $stmt = mysqli_prepare($conexion, $sql);
        $ok = mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);
        return $ok;
    }

    function insertarConvocatoria($nombre, $tipo, $fecha_inicio, $fecha_fin, $clave_registro, $responsable_id, $conexion)
    {
        $sql = "INSERT INTO convocatorias
                (nombre, tipo, fecha_inicio, fecha_fin, clave_registro, responsable_id)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "sssssi",
            $nombre,
            $tipo,
            $fecha_inicio,
            $fecha_fin,
            $clave_registro,
            $responsable_id
        );
        $ok = mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);
        return $ok;
    }

    function insertarProyecto(
        $convocatoria_id,
        $nombre_persona,
        $codigo,
        $fecha_ingreso,
        $nombre_proyecto,
        $fecha_terminado,
        $conexion
    ) {
        $sql = "INSERT INTO proyectos
                (convocatoria_id, nombre_persona, codigo, fecha_ingreso, nombre_proyecto, fecha_terminado)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "isssss",
            $convocatoria_id,
            $nombre_persona,
            $codigo,
            $fecha_ingreso,
            $nombre_proyecto,
            $fecha_terminado
        );
        $ok = mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);
        return $ok;
    }
}
