<?php
require_once __DIR__ . '/auth.php';

function renderNav(string $active = ''): string {
    $user   = currentUser();
    $perfil = $user['perfil'] ?? '';

    $all = [
        'index.php'        => ['🎫', 'Tickets',      ['agente','coordinador']],
        'mis_tickets.php'  => ['📋', 'Mis Tickets',  ['usuario']],
        'nuevo_ticket.php' => ['➕', 'Nuevo Ticket',  ['usuario','agente','coordinador']],
        'agentes.php'      => ['👥', 'Agentes',       ['coordinador']],
        'usuarios.php'     => ['🔑', 'Usuarios',      ['coordinador']],
        'reportes.php'     => ['📊', 'Reportes Internos',      ['agente','coordinador']],
        'tareas.php'       => ['✅', 'Tareas',        ['agente','coordinador']],
        'indicadores.php'  => ['📈', 'Indicadores Externos',   ['coordinador','agente']],
    ];

    $badgeLabel = ['coordinador'=>'Coordinador','agente'=>'Agente','usuario'=>'Usuario'];
    $badgeColor = ['coordinador'=>'var(--accent2)','agente'=>'var(--accent)','usuario'=>'var(--green)'];
    $bc = $badgeColor[$perfil] ?? 'var(--muted)';
    $bl = $badgeLabel[$perfil] ?? $perfil;

    /* ── Favicon + Nav desktop ── */
    $html  = '<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">';
    $html .= '<nav id="mainNav">';
    $html .= '<a href="index.php" class="nav-brand" style="text-decoration:none;">'
           . '<img src="sara_logo.png" alt="SARA APP Support" style="height:40px;width:auto;object-fit:contain;display:block;">'
           . '</a>';

    /* Links visibles en desktop */
    $html .= '<div class="nav-links">';
    foreach ($all as $file => [$icon, $label, $perfiles]) {
        if (!in_array($perfil, $perfiles)) continue;
        $cls = ($active === $file) ? ' class="active"' : '';
        $html .= "<a href=\"$file\"$cls>$icon $label</a>";
    }
    $html .= '</div>';

    /* Info usuario + salir (desktop) */
    if ($user) {
        $nombre = htmlspecialchars($user['nombre']);
        $html .= '<div class="nav-end">';
        $html .= "<span class=\"nav-user-info\"><span class=\"nav-perfil-badge\" style=\"background:{$bc}\">{$bl}</span>{$nombre}</span>";
        $html .= '<a href="logout.php" class="nav-logout">Salir</a>';
        $html .= '</div>';
    }

    /* Botón hamburguesa (móvil/tablet) */
    $html .= '<button class="nav-hamburger" id="navHamburger" aria-label="Menú" onclick="toggleDrawer()">';
    $html .= '<span></span><span></span><span></span>';
    $html .= '</button>';

    $html .= '</nav>';

    /* ── Drawer móvil ── */
    $html .= '<div class="nav-drawer" id="navDrawer">';
    if ($user) {
        $nombre = htmlspecialchars($user['nombre']);
        $html .= "<div class=\"nav-drawer-user\"><span class=\"nav-perfil-badge\" style=\"background:{$bc};\">{$bl}</span>{$nombre}</div>";
        $html .= '<div class="nav-drawer-sep"></div>';
    }
    foreach ($all as $file => [$icon, $label, $perfiles]) {
        if (!in_array($perfil, $perfiles)) continue;
        $cls = ($active === $file) ? ' class="active"' : '';
        $html .= "<a href=\"$file\"$cls onclick=\"closeDrawer()\">$icon $label</a>";
    }
    $html .= '<div class="nav-drawer-sep"></div>';
    $html .= '<a href="logout.php">🚪 Cerrar Sesión</a>';
    $html .= '</div>';

    /* JS del menú móvil */
    $html .= <<<JS
<script>
function toggleDrawer() {
    var d = document.getElementById('navDrawer');
    var b = document.getElementById('navHamburger');
    var open = d.classList.toggle('open');
    // Animar las 3 líneas del hamburguesa
    var spans = b.querySelectorAll('span');
    if (open) {
        spans[0].style.transform = 'translateY(6px) rotate(45deg)';
        spans[1].style.opacity   = '0';
        spans[2].style.transform = 'translateY(-6px) rotate(-45deg)';
    } else {
        spans[0].style.transform = '';
        spans[1].style.opacity   = '';
        spans[2].style.transform = '';
    }
}
function closeDrawer() {
    var d = document.getElementById('navDrawer');
    var b = document.getElementById('navHamburger');
    d.classList.remove('open');
    if (b) {
        var spans = b.querySelectorAll('span');
        spans[0].style.transform = '';
        spans[1].style.opacity   = '';
        spans[2].style.transform = '';
    }
}
// Cerrar drawer al hacer clic fuera del nav
document.addEventListener('click', function(e) {
    var nav    = document.getElementById('mainNav');
    var drawer = document.getElementById('navDrawer');
    if (nav && drawer && !nav.contains(e.target) && drawer.classList.contains('open')) {
        closeDrawer();
    }
});
// Cerrar drawer si la pantalla pasa a desktop (≥ 1024px)
window.addEventListener('resize', function() {
    if (window.innerWidth >= 1024) closeDrawer();
});
</script>
JS;

    return $html;
}

function generateNumero(): string {
    return 'TKT-' . strtoupper(substr(md5(uniqid()), 0, 6));
}

function badgeEstado(string $estado): string {
    $map = ['Abierto'=>'b-abierto','En Progreso'=>'b-progreso','Resuelto'=>'b-resuelto','Cerrado'=>'b-cerrado'];
    $cls = $map[$estado] ?? 'b-cerrado';
    return "<span class=\"badge {$cls}\">{$estado}</span>";
}

function badgePrioridad(string $p): string {
    $map = ['Baja'=>'b-baja','Media'=>'b-media','Alta'=>'b-alta','Critica'=>'b-critica'];
    $cls = $map[$p] ?? 'b-media';
    return "<span class=\"badge {$cls}\">{$p}</span>";
}

function paginar(int $total, int $porPagina, int $paginaActual, array $params = []): array {
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $paginaActual = max(1, min($paginaActual, $totalPaginas));
    $offset       = ($paginaActual - 1) * $porPagina;
    return [
        'total'        => $total,
        'por_pagina'   => $porPagina,
        'pagina'       => $paginaActual,
        'total_paginas'=> $totalPaginas,
        'offset'       => $offset,
        'params'       => $params,
    ];
}

function renderPaginacion(array $p): string {
    if ($p['total_paginas'] <= 1) return '';

    $params  = $p['params'];
    $actual  = $p['pagina'];
    $total   = $p['total_paginas'];
    $desde   = max(1, $actual - 2);
    $hasta   = min($total, $actual + 2);

    $url = function(int $pg) use ($params): string {
        return '?' . http_build_query(array_merge($params, ['pag' => $pg]));
    };

    $html  = '<div class="pagination">';
    $html .= '<span class="pag-info">Página ' . $actual . ' de ' . $total . ' · ' . $p['total'] . ' registros</span>';
    $html .= '<div class="pag-btns">';

    // Primera + Anterior
    if ($actual > 1) {
        $html .= '<a href="' . $url(1)         . '" class="pag-btn" title="Primera">«</a>';
        $html .= '<a href="' . $url($actual-1) . '" class="pag-btn" title="Anterior">‹</a>';
    } else {
        $html .= '<span class="pag-btn disabled">«</span>';
        $html .= '<span class="pag-btn disabled">‹</span>';
    }

    // Páginas numéricas
    if ($desde > 1) $html .= '<span class="pag-btn disabled">…</span>';
    for ($i = $desde; $i <= $hasta; $i++) {
        $cls  = ($i === $actual) ? 'pag-btn active' : 'pag-btn';
        $html .= '<a href="' . $url($i) . '" class="' . $cls . '">' . $i . '</a>';
    }
    if ($hasta < $total) $html .= '<span class="pag-btn disabled">…</span>';

    // Siguiente + Última
    if ($actual < $total) {
        $html .= '<a href="' . $url($actual+1) . '" class="pag-btn" title="Siguiente">›</a>';
        $html .= '<a href="' . $url($total)    . '" class="pag-btn" title="Última">»</a>';
    } else {
        $html .= '<span class="pag-btn disabled">›</span>';
        $html .= '<span class="pag-btn disabled">»</span>';
    }

    $html .= '</div></div>';
    return $html;
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'Hace ' . $diff . 's';
    if ($diff < 3600)  return 'Hace ' . round($diff/60) . 'min';
    if ($diff < 86400) return 'Hace ' . round($diff/3600) . 'h';
    return 'Hace ' . round($diff/86400) . 'd';
}
