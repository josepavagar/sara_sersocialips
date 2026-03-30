<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

/* ─── Helpers de sesión ───────────────────────────────────── */
function isLoggedIn(): bool {
    return isset($_SESSION['hd_user']);
}

function currentUser(): ?array {
    return $_SESSION['hd_user'] ?? null;
}

function userPerfil(): string {
    return $_SESSION['hd_user']['perfil'] ?? '';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }
}

/** Verifica que el usuario tenga uno de los perfiles indicados */
function requirePerfil(string ...$perfiles): void {
    requireLogin();
    if (!in_array(userPerfil(), $perfiles)) {
        header('Location: ' . BASE_URL . '/modules/auth/acceso_denegado.php');
        exit;
    }
}

function can(string $perfil): bool {
    return userPerfil() === $perfil;
}

function canAny(string ...$perfiles): bool {
    return in_array(userPerfil(), $perfiles);
}

/* ─── Auto-asignación de tickets por aplicación ──────────── */
function autoAsignar($db, int $ticket_id, string $aplicacion): void {
    if (!$aplicacion) return;

    $asistencial = ['Siesa Salud','Siesa Laboratorios','Global Health','Sagicc','Zagilad','Api Siesa','LimeSurvey'];
    $erp         = ['Zeus Contabilidad','Zeus Nomina','Zeus Nomina WEB','Zeus Inventario','Zeus Activo Fijos','Zeus Excel'];
    $riesgo      = ['SerAgil','Sibacom'];
    $beneficios  = ['PEC','Sifood','TugoFood'];

    $agente_id = null;

    if (in_array($aplicacion, array_merge($asistencial, $erp))) {
        // Round-robin entre IDs 1 y 2 → el que tenga menos tickets abiertos
        $c1 = (int)$db->query("SELECT COUNT(*) c FROM tickets WHERE agente_id=1 AND estado IN ('Abierto','En Progreso')")->fetch_assoc()['c'];
        $c2 = (int)$db->query("SELECT COUNT(*) c FROM tickets WHERE agente_id=2 AND estado IN ('Abierto','En Progreso')")->fetch_assoc()['c'];
        $agente_id = ($c1 <= $c2) ? 1 : 2;
    } elseif (in_array($aplicacion, $riesgo)) {
        $agente_id = 4;
    } elseif (in_array($aplicacion, $beneficios)) {
        $agente_id = 5;
    }

    if ($agente_id) {
        $stmt = $db->prepare("SELECT id, nombre FROM agentes WHERE id=? AND activo=1");
        $stmt->bind_param('i', $agente_id);
        $stmt->execute();
        $ag = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($ag) {
            $nombre  = $ag['nombre'];
            $mensaje = "Asignación automática a: $nombre (regla por aplicación)";

            $stmt = $db->prepare("UPDATE tickets SET agente_id=?, agente=? WHERE id=?");
            $stmt->bind_param('isi', $agente_id, $nombre, $ticket_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare("INSERT INTO comentarios (ticket_id, autor, tipo, mensaje) VALUES (?, 'Sistema', 'Asignacion', ?)");
            $stmt->bind_param('is', $ticket_id, $mensaje);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>
