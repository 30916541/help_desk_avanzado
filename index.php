<?php
session_start();
require 'db.php';

$errores = [];
$usuario = $asunto = $mensaje = '';
$editarTicket = null;
$pagina = isset($_GET['pagina']) ? '&pagina=' . max(1, (int)$_GET['pagina']) : '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Export CSV
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tickets_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Usuario', 'Asunto', 'Mensaje', 'Estatus', 'Fecha']);
    $stmt = $pdo->query('SELECT * FROM tickets ORDER BY fecha_creacion DESC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['id'], $row['usuario'], $row['asunto'], $row['mensaje'], $row['estatus'], $row['fecha_creacion']]);
    }
    fclose($output);
    exit;
}

// Eliminar
if (isset($_GET['eliminar'])) {
    $id = (int) $_GET['eliminar'];
    $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    header('Location: index.php?eliminado=1' . $pagina);
    exit;
}

// Editar
if (isset($_GET['editar'])) {
    $id = (int) $_GET['editar'];
    $sentencia = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
    $sentencia->execute([$id]);
    $editarTicket = $sentencia->fetch(PDO::FETCH_ASSOC);
}

// Validación y guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Error de validación CSRF.');
    }

    $usuario = trim($_POST['usuario'] ?? '');
    $asunto  = trim($_POST['asunto'] ?? '');
    $mensaje = trim($_POST['mensaje'] ?? '');
    $id      = $_POST['id'] ?? '';

    if ($usuario === '') {
        $errores['usuario'] = 'El usuario es obligatorio.';
    } elseif (strlen($usuario) < 3) {
        $errores['usuario'] = 'El usuario debe tener al menos 3 caracteres.';
    } elseif (strlen($usuario) > 100) {
        $errores['usuario'] = 'El usuario no puede tener m\u00e1s de 100 caracteres.';
    } elseif (!preg_match('/^[a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ@._\-\']+$/', $usuario)) {
        $errores['usuario'] = 'El usuario solo puede contener letras, n\u00fameros, espacios, @, ., -, _';
    }

    if ($asunto === '') {
        $errores['asunto'] = 'El asunto es obligatorio.';
    } elseif (strlen($asunto) < 5) {
        $errores['asunto'] = 'El asunto debe tener al menos 5 caracteres.';
    } elseif (strlen($asunto) > 255) {
        $errores['asunto'] = 'El asunto no puede tener m\u00e1s de 255 caracteres.';
    }

    if ($mensaje === '') {
        $errores['mensaje'] = 'El mensaje es obligatorio.';
    } elseif (strlen($mensaje) < 10) {
        $errores['mensaje'] = 'El mensaje debe tener al menos 10 caracteres.';
    } elseif (strlen($mensaje) > 500) {
        $errores['mensaje'] = 'El mensaje no puede tener m\u00e1s de 500 caracteres.';
    }

    if (empty($errores)) {
        if ($id === '') {
            $pdo->prepare('INSERT INTO tickets (usuario, asunto, mensaje) VALUES (?, ?, ?)')->execute([$usuario, $asunto, $mensaje]);
            header('Location: index.php?registrado=1');
        } else {
            $estatus = trim($_POST['estatus'] ?? 'Abierto');
            $pdo->prepare('UPDATE tickets SET usuario = ?, asunto = ?, mensaje = ?, estatus = ? WHERE id = ?')->execute([$usuario, $asunto, $mensaje, $estatus, $id]);
            header('Location: index.php?actualizado=1');
        }
        exit;
    }
}

// Búsqueda
$buscar = trim($_GET['buscar'] ?? '');
$where = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE usuario LIKE ? OR asunto LIKE ? OR estatus LIKE ?';
    $like = "%$buscar%";
    $params = [$like, $like, $like];
}

$porPagina = 5;
$paginaActual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($paginaActual - 1) * $porPagina;

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets $where");
$totalStmt->execute($params);
$totalTickets = $totalStmt->fetchColumn();
$totalPaginas = max(1, ceil($totalTickets / $porPagina));

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
    $offset = ($paginaActual - 1) * $porPagina;
}

$sql = "SELECT * FROM tickets $where ORDER BY fecha_creacion DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$i = 1;
foreach ($params as $p) { $stmt->bindValue($i++, $p); }
$stmt->bindValue($i++, (int)$porPagina, PDO::PARAM_INT);
$stmt->bindValue($i++, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mesa de Ayuda - Gestión de Incidencias</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['"DM Sans"', 'system-ui', 'sans-serif'] },
                    colors: {
                        surface: 'var(--color-surface)',
                        'surface-alt': 'var(--color-surface-alt)',
                        border: 'var(--color-border)',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                        'scale-in': 'scaleIn 0.2s ease-out',
                        'stagger-in': 'staggerIn 0.4s ease-out forwards',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { opacity: '0', transform: 'translateY(10px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        scaleIn: { '0%': { opacity: '0', transform: 'scale(0.95)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                        staggerIn: { '0%': { opacity: '0', transform: 'translateY(8px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                    }
                }
            }
        }
    </script>
    <style>
        :root { --color-surface: #ffffff; --color-surface-alt: #f8fafc; --color-border: #e2e8f0; color-scheme: light; }
        .dark { --color-surface: #1e293b; --color-surface-alt: #0f172a; --color-border: #334155; color-scheme: dark; }
        .dark body { background: #0f172a; color: #e2e8f0; }
        @media (prefers-reduced-motion: reduce) {
            .animate-fade-in, .animate-slide-up, .animate-scale-in, .animate-stagger-in { animation: none !important; }
            *, *::before, *::after { transition-duration: 0.01ms !important; }
        }
        .stagger-row { opacity: 0; animation: staggerIn 0.4s ease-out forwards; }
        .dark .bg-white\2f \2f \2f \2f \2f \2f { background-color: var(--color-surface); }
    </style>
    <meta name="theme-color" content="#0f172a">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=Outfit:wght@700;800;900&display=swap" rel="stylesheet">
</head>
<body class="font-sans bg-gradient-to-br from-slate-100 to-slate-200 text-gray-900 antialiased min-h-screen transition-colors duration-300 dark:bg-slate-950 dark:text-slate-200" style="touch-action: manipulation;">

    <header class="bg-gradient-to-r from-slate-900 to-slate-800 text-white shadow-lg dark:from-slate-950 dark:to-slate-900">
        <div class="max-w-5xl mx-auto px-5 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                </div>
                <div>
                    <h1 class="text-xl font-extrabold tracking-tight" style="font-family: 'Outfit', sans-serif;">Mesa de Ayuda</h1>
                    <p class="text-xs text-slate-400 font-medium">Gesti&oacute;n de Incidencias</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button id="theme-toggle" class="w-9 h-9 bg-white/10 rounded-xl flex items-center justify-center hover:bg-white/20 transition-colors cursor-pointer" aria-label="Cambiar tema">
                    <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    <svg class="moon-icon hidden" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                </button>
                <span class="text-xs bg-white/10 px-3 py-1.5 rounded-full font-semibold">
                    <?php echo $totalTickets; ?> ticket<?php echo $totalTickets !== 1 ? 's' : ''; ?>
                </span>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-5 py-6">

    <?php
    $toastMensaje = '';
    if (isset($_GET['registrado'])) $toastMensaje = 'Ticket registrado exitosamente.';
    elseif (isset($_GET['actualizado'])) $toastMensaje = 'Ticket actualizado exitosamente.';
    elseif (isset($_GET['eliminado'])) $toastMensaje = 'Ticket eliminado exitosamente.';
    ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-8 overflow-hidden animate-fade-in dark:bg-slate-800 dark:border-slate-700">
        <div class="bg-gradient-to-r from-blue-600 to-blue-500 px-5 py-3.5 flex items-center gap-2.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white/90 shrink-0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
            <h2 class="text-lg font-bold text-white" style="font-family: 'Outfit', sans-serif;">Nuevo Ticket</h2>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="usuario" class="block font-semibold text-sm text-gray-700 mb-1">Usuario</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-500" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input type="text" id="usuario" name="usuario" placeholder="Tu nombre o correo" value="<?php echo htmlspecialchars($usuario); ?>" required autocomplete="name" class="w-full pl-9 pr-3 py-2.5 border border-gray-300 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
                    </div>
                    <?php if (isset($errores['usuario'])): ?>
                        <div class="mt-1.5 bg-red-50 text-red-700 border border-red-200 p-2 rounded-lg text-xs font-medium flex items-center gap-1.5" role="alert">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?php echo $errores['usuario']; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="asunto" class="block font-semibold text-sm text-gray-700 mb-1 dark:text-slate-300">Asunto</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-500" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>
                        </span>
                        <input type="text" id="asunto" name="asunto" placeholder="Asunto del problema" value="<?php echo htmlspecialchars($asunto); ?>" required autocomplete="off" class="w-full pl-9 pr-3 py-2.5 border border-gray-300 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
                    </div>
                    <?php if (isset($errores['asunto'])): ?>
                        <div class="mt-1.5 bg-red-50 text-red-700 border border-red-200 p-2 rounded-lg text-xs font-medium flex items-center gap-1.5" role="alert">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?php echo $errores['asunto']; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <label for="mensaje" class="block font-semibold text-sm text-gray-700 mb-1 dark:text-slate-300">Mensaje</label>
                <div class="relative">
                    <span class="absolute left-3 top-3 text-gray-400 dark:text-slate-500" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </span>
                    <textarea name="mensaje" id="mensaje" rows="4" placeholder="Describe detalladamente tu situacion..." required maxlength="500" class="w-full pl-9 pr-3 py-2.5 border border-gray-300 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"><?php echo htmlspecialchars($mensaje); ?></textarea>
                </div>
                <div class="flex justify-between items-center mt-1">
                    <?php if (isset($errores['mensaje'])): ?>
                        <div class="bg-red-50 text-red-700 border border-red-200 p-2 rounded-lg text-xs font-medium flex items-center gap-1.5" role="alert">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?php echo $errores['mensaje']; ?>
                        </div>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span id="char-count" class="text-xs text-gray-400 dark:text-slate-500">0/500</span>
                </div>
            </div>

            <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-500 text-white px-5 py-2.5 rounded-lg font-bold hover:from-blue-700 hover:to-blue-600 transition-all duration-200 shadow-sm hover:shadow-md active:scale-[0.98] cursor-pointer">Enviar Solicitud</button>
        </form>
    </div>

    <hr>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden animate-fade-in dark:bg-slate-800 dark:border-slate-700">
        <div class="bg-gradient-to-r from-slate-900 to-slate-800 px-5 py-3.5 flex items-center justify-between dark:from-slate-950 dark:to-slate-900">
            <div class="flex items-center gap-2.5">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white/90 shrink-0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                <h2 class="text-lg font-bold text-white" style="font-family: 'Outfit', sans-serif;">Incidencias Registradas</h2>
            </div>
            <div class="flex items-center gap-2">
                <a href="?exportar=1" class="text-xs bg-white/10 text-white px-2.5 py-1.5 rounded-lg hover:bg-white/20 transition-colors no-underline flex items-center gap-1.5" title="Exportar CSV">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    CSV
                </a>
                <?php if (!empty($incidencias)): ?>
                <span class="text-xs bg-white/10 text-white px-2.5 py-1 rounded-full font-medium"><?php echo $totalTickets; ?> registro<?php echo $totalTickets !== 1 ? 's' : ''; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-4 py-3 border-b border-gray-100 dark:border-slate-700">
            <form method="GET" class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </span>
                <input type="text" name="buscar" placeholder="Buscar por usuario, asunto o estatus..." value="<?php echo htmlspecialchars($buscar); ?>" class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
            </form>
        </div>

        <?php if (empty($incidencias)): ?>
            <div class="flex flex-col items-center justify-center py-14 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 dark:text-slate-600 mb-4"><path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                <p class="text-gray-500 font-medium text-sm dark:text-slate-400"><?php echo $buscar ? 'No se encontraron resultados para "' . htmlspecialchars($buscar) . '".' : 'No hay incidencias registradas actualmente.'; ?></p>
                <p class="text-gray-400 text-xs mt-1 dark:text-slate-500"><?php echo $buscar ? 'Intenta con otros t&eacute;rminos de b&uacute;squeda.' : 'Crea un nuevo ticket usando el formulario de arriba.'; ?></p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider dark:bg-slate-700/50 dark:text-slate-400">
                        <th class="p-3.5 text-left font-semibold">ID</th>
                        <th class="p-3.5 text-left font-semibold">Usuario</th>
                        <th class="p-3.5 text-left font-semibold">Asunto</th>
                        <th class="p-3.5 text-left font-semibold hidden md:table-cell">Mensaje</th>
                        <th class="p-3.5 text-center font-semibold">Estatus</th>
                        <th class="p-3.5 text-left font-semibold hidden sm:table-cell">Fecha</th>
                        <th class="p-3.5 text-center font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <?php foreach ($incidencias as $i => $incidencia): ?>
                    <tr class="stagger-row hover:bg-blue-50/50 transition-colors dark:hover:bg-slate-700/50 <?php echo $i % 2 === 0 ? 'bg-white dark:bg-slate-800' : 'bg-gray-50/50 dark:bg-slate-800/50'; ?>" style="animation-delay: <?php echo $i * 0.05; ?>s">
                        <td class="p-3.5 text-sm text-gray-400 font-mono dark:text-slate-500">#<?php echo $incidencia['id']; ?></td>
                        <td class="p-3.5 text-sm font-medium text-gray-800 dark:text-slate-200"><?php echo htmlspecialchars($incidencia['usuario']); ?></td>
                        <td class="p-3.5 text-sm text-gray-700 dark:text-slate-300"><?php echo htmlspecialchars($incidencia['asunto']); ?></td>
                        <td class="p-3.5 text-sm text-gray-500 max-w-[200px] truncate hidden md:table-cell dark:text-slate-400"><?php echo htmlspecialchars($incidencia['mensaje']); ?></td>
                        <td class="p-3.5 text-center">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $incidencia['estatus'] === 'Cerrado' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $incidencia['estatus'] === 'Cerrado' ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span>
                                <?php echo htmlspecialchars($incidencia['estatus']); ?>
                            </span>
                        </td>
                        <td class="p-3.5 text-sm text-gray-400 whitespace-nowrap hidden sm:table-cell dark:text-slate-500">
                            <time datetime="<?php echo $incidencia['fecha_creacion']; ?>"><?php echo htmlspecialchars($incidencia['fecha_creacion']); ?></time>
                        </td>
                        <td class="p-3.5 text-center">
                            <div class="flex gap-1.5 justify-center">
                                <a href="?editar=<?php echo $incidencia['id'] . $pagina; ?>" class="p-2 rounded-lg no-underline bg-amber-500 text-white hover:bg-amber-600 hover:shadow-sm transition-all active:scale-95" title="Editar" aria-label="Editar ticket">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                </a>
                                <a href="#" data-eliminar="<?php echo $incidencia['id'] . $pagina; ?>" class="p-2 rounded-lg no-underline bg-red-500 text-white hover:bg-red-600 hover:shadow-sm transition-all active:scale-95" title="Eliminar" aria-label="Eliminar ticket">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPaginas > 1): ?>
        <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100 bg-gray-50/50 dark:border-slate-700 dark:bg-slate-800/50">
            <p class="text-xs text-gray-500 dark:text-slate-400">
                Página <?php echo $paginaActual; ?> de <?php echo $totalPaginas; ?>
            </p>
            <div class="flex gap-1">
                <?php if ($paginaActual > 1): ?>
                <a href="?pagina=<?php echo $paginaActual - 1; ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold no-underline bg-white text-gray-600 border border-gray-200 hover:bg-gray-50 hover:border-gray-300 transition-all dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600 dark:hover:bg-slate-600">Anterior</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $paginaActual - 2);
                $endPage = min($totalPaginas, $paginaActual + 2);
                for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                <a href="?pagina=<?php echo $p; ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold no-underline transition-all <?php echo $p === $paginaActual ? 'bg-blue-600 text-white shadow-sm dark:bg-blue-500' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50 dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600 dark:hover:bg-slate-600'; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>

                <?php if ($paginaActual < $totalPaginas): ?>
                <a href="?pagina=<?php echo $paginaActual + 1; ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold no-underline bg-white text-gray-600 border border-gray-200 hover:bg-gray-50 hover:border-gray-300 transition-all dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600 dark:hover:bg-slate-600">Siguiente</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($editarTicket): ?>
    <div id="modal-editar" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 animate-fade-in">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-11/12 max-w-lg max-h-[90vh] overflow-y-auto modal-contenido animate-scale-in">
            <div class="bg-gradient-to-r from-blue-600 to-blue-500 px-5 py-3.5 flex items-center justify-between rounded-t-xl">
                <div class="flex items-center gap-2.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white/90 shrink-0"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                    <h2 class="text-lg font-bold text-white" style="font-family: 'Outfit', sans-serif;">Editar Ticket</h2>
                </div>
                <a href="index.php<?php echo $pagina ? '?' . substr($pagina, 1) : ''; ?>" class="text-white/80 hover:text-white transition-colors" aria-label="Cerrar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </a>
            </div>
            <form method="POST" class="p-5 space-y-4" id="modal-edit-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="id" value="<?php echo $editarTicket['id']; ?>">

                <div>
                    <label for="modal-usuario" class="block font-semibold text-sm text-gray-700 mb-1 dark:text-slate-300">Usuario</label>
                    <input type="text" id="modal-usuario" name="usuario" value="<?php echo htmlspecialchars($editarTicket['usuario']); ?>" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>

                <div>
                    <label for="modal-asunto" class="block font-semibold text-sm text-gray-700 mb-1 dark:text-slate-300">Asunto</label>
                    <input type="text" id="modal-asunto" name="asunto" value="<?php echo htmlspecialchars($editarTicket['asunto']); ?>" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>

                <div>
                    <label for="modal-mensaje" class="block font-semibold text-sm text-gray-700 mb-1 dark:text-slate-300">Mensaje</label>
                    <textarea id="modal-mensaje" name="mensaje" rows="4" required class="w-full px-3 py-2.5 border border-gray-300 rounded-lg font-sans text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow dark:bg-slate-700 dark:border-slate-600 dark:text-white"><?php echo htmlspecialchars($editarTicket['mensaje']); ?></textarea>
                </div>

                <div>
                    <label for="modal-estatus" class="block font-semibold text-sm text-gray-700 mb-1 dark:text-slate-300">Estatus</label>
                    <div class="relative custom-select" data-value="<?php echo $editarTicket['estatus']; ?>">
                        <input type="hidden" name="estatus" value="<?php echo $editarTicket['estatus']; ?>">
                        <button type="button" class="w-full flex items-center justify-between px-3 py-2.5 border-2 rounded-lg font-sans text-sm font-medium cursor-pointer transition-shadow <?php echo $editarTicket['estatus'] === 'Cerrado' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-amber-300 bg-amber-50 text-amber-700'; ?> dark:<?php echo $editarTicket['estatus'] === 'Cerrado' ? 'bg-emerald-900/30 text-emerald-300 border-emerald-700' : 'bg-amber-900/30 text-amber-300 border-amber-700'; ?>">
                            <span class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full <?php echo $editarTicket['estatus'] === 'Cerrado' ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span>
                                <span><?php echo $editarTicket['estatus']; ?></span>
                            </span>
                            <svg class="dropdown-chevron transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="dropdown-menu fixed z-[9999] bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-xl shadow-xl overflow-hidden hidden" style="min-width: 200px;">
                            <button type="button" data-value="Abierto" class="w-full flex items-center gap-3 px-3.5 py-3 text-sm font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors cursor-pointer border-b border-gray-100 dark:border-slate-600">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shrink-0"></span>
                                <span>Abierto</span>
                            </button>
                            <button type="button" data-value="Cerrado" class="w-full flex items-center gap-3 px-3.5 py-3 text-sm font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 transition-colors cursor-pointer">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>Cerrado</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex gap-2.5 pt-2">
                    <button type="submit" class="flex-1 bg-gradient-to-r from-blue-600 to-blue-500 text-white px-5 py-2.5 rounded-lg font-bold hover:from-blue-700 hover:to-blue-600 transition-all duration-200 shadow-sm active:scale-[0.98] cursor-pointer">Guardar Cambios</button>
                    <a href="index.php<?php echo $pagina ? '?' . substr($pagina, 1) : ''; ?>" class="px-5 py-2.5 rounded-lg font-bold no-underline inline-block bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors cursor-pointer dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div id="modal-confirmar" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 animate-fade-in" style="display: none;">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl text-center max-w-sm w-11/12 modal-contenido animate-scale-in">
            <div class="p-8">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-4"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <p class="text-lg font-bold text-gray-800 mb-1 dark:text-slate-200">¿Estás seguro de eliminar este ticket?</p>
                <p class="text-gray-500 text-sm dark:text-slate-400">Esta acción no se puede deshacer.</p>
                <div class="flex gap-2.5 mt-6 justify-center">
                    <a href="#" id="btn-confirmar-si" class="px-5 py-2.5 rounded-lg text-sm font-bold no-underline inline-block bg-red-500 text-white hover:bg-red-600 transition-all shadow-sm active:scale-95">Sí, eliminar</a>
                    <a href="#" id="btn-confirmar-no" class="px-5 py-2.5 rounded-lg font-bold no-underline inline-block bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors cursor-pointer dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600">Cancelar</a>
                </div>
            </div>
        </div>
    </div>

    </main>

    <div id="toast-error" class="fixed top-4 right-4 z-[9999] translate-x-[120%] opacity-0 transition-all duration-500 ease-out" role="alert" aria-live="polite">
        <div class="flex items-center gap-3 bg-white dark:bg-slate-800 border border-red-200 dark:border-red-800 shadow-xl rounded-xl px-4 py-3.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span id="toast-error-mensaje" class="text-sm font-semibold text-gray-800 dark:text-slate-200"></span>
            <button id="toast-error-cerrar" class="ml-2 text-gray-400 hover:text-gray-600 cursor-pointer p-0.5" aria-label="Cerrar">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>

    <div id="toast" class="fixed top-4 right-4 z-[9999] translate-x-[120%] opacity-0 transition-all duration-500 ease-out" role="alert" aria-live="polite">
        <div class="flex items-center gap-3 bg-white dark:bg-slate-800 border border-emerald-200 dark:border-emerald-800 shadow-xl rounded-xl px-4 py-3.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
            <span id="toast-mensaje" class="text-sm font-semibold text-gray-800 dark:text-slate-200"></span>
            <button id="toast-cerrar" class="ml-2 text-gray-400 hover:text-gray-600 cursor-pointer p-0.5" aria-label="Cerrar">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>

    <script>
        // Dark mode
        (function() {
            var theme = localStorage.getItem('theme');
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
            document.getElementById('theme-toggle').onclick = function() {
                var isDark = document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                var sun = this.querySelector('.sun-icon');
                var moon = this.querySelector('.moon-icon');
                if (sun && moon) {
                    sun.classList.toggle('hidden', isDark);
                    moon.classList.toggle('hidden', !isDark);
                }
            };
            var isDark = document.documentElement.classList.contains('dark');
            var sun = document.getElementById('theme-toggle').querySelector('.sun-icon');
            var moon = document.getElementById('theme-toggle').querySelector('.moon-icon');
            if (sun && moon) {
                sun.classList.toggle('hidden', isDark);
                moon.classList.toggle('hidden', !isDark);
            }
        })();

        // Character counter
        (function() {
            var ta = document.getElementById('mensaje');
            var cc = document.getElementById('char-count');
            if (ta && cc) {
                function update() { cc.textContent = ta.value.length + '/500'; }
                ta.addEventListener('input', update);
                update();
            }
        })();

        // Busqueda auto-submit (solo si hay cambios significativos)
        (function() {
            var input = document.querySelector('input[name="buscar"]');
            if (input) {
                var timer, last = input.value;
                input.addEventListener('input', function() {
                    clearTimeout(timer);
                    timer = setTimeout(function() {
                        if (input.value !== last) { last = input.value; input.form.submit(); }
                    }, 500);
                });
            }
        })();

        // Toast error (llamada desde PHP si hay errores)
        function mostrarToastError(msg) {
            var t = document.getElementById('toast-error');
            var m = document.getElementById('toast-error-mensaje');
            if (!t || !m) return;
            m.textContent = msg;
            requestAnimationFrame(function() {
                t.classList.remove('translate-x-[120%]', 'opacity-0');
                t.classList.add('translate-x-0', 'opacity-100');
            });
            var timer = setTimeout(function() {
                t.classList.remove('translate-x-0', 'opacity-100');
                t.classList.add('translate-x-[120%]', 'opacity-0');
            }, 5000);
            document.getElementById('toast-error-cerrar').onclick = function() {
                clearTimeout(timer);
                t.classList.remove('translate-x-0', 'opacity-100');
                t.classList.add('translate-x-[120%]', 'opacity-0');
            };
        }
        <?php if (!empty($errores)): ?>
        mostrarToastError('Corrige los errores en el formulario.');
        <?php endif; ?>

        // Toast success
        <?php if ($toastMensaje): ?>
        (function() {
            var t = document.getElementById('toast');
            var m = document.getElementById('toast-mensaje');
            m.textContent = '<?php echo $toastMensaje; ?>';
            requestAnimationFrame(function() {
                t.classList.remove('translate-x-[120%]', 'opacity-0');
                t.classList.add('translate-x-0', 'opacity-100');
            });
            var timer = setTimeout(function() {
                t.classList.remove('translate-x-0', 'opacity-100');
                t.classList.add('translate-x-[120%]', 'opacity-0');
            }, 4000);
            document.getElementById('toast-cerrar').onclick = function() {
                clearTimeout(timer);
                t.classList.remove('translate-x-0', 'opacity-100');
                t.classList.add('translate-x-[120%]', 'opacity-0');
            };
        })();
        <?php endif; ?>

        // Dates formatting
        document.querySelectorAll('time[datetime]').forEach(function(el) {
            try {
                var d = new Date(el.getAttribute('datetime').replace(' ', 'T'));
                if (!isNaN(d)) el.textContent = d.toLocaleDateString('es-ES', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            } catch(e) {}
        });

        // Modal dirty check
        (function() {
            var form = document.getElementById('modal-edit-form');
            if (form) {
                var original = new FormData(form);
                var closeBtn = form.closest('.modal-contenido').querySelector('[aria-label="Cerrar"]');
                if (closeBtn) {
                    closeBtn.onclick = function(e) {
                        var current = new FormData(form);
                        var changed = false;
                        for (var key of original.keys()) {
                            if (original.get(key) !== current.get(key)) { changed = true; break; }
                        }
                        if (changed && !confirm('Hay cambios sin guardar. ¿Estás seguro de cerrar?')) {
                            e.preventDefault();
                        }
                    };
                }
            }
        })();

        document.addEventListener('click', function (e) {
            var eliminar = e.target.closest('[data-eliminar]');
            if (eliminar) {
                e.preventDefault();
                var id = eliminar.getAttribute('data-eliminar');
                var modal = document.getElementById('modal-confirmar');
                var btnSi = document.getElementById('btn-confirmar-si');
                modal.style.display = '';
                btnSi.href = '?eliminar=' + id;
            }

            if (e.target.closest('#btn-confirmar-no') || e.target.closest('#modal-confirmar') && !e.target.closest('.modal-contenido')) {
                e.preventDefault();
                document.getElementById('modal-confirmar').style.display = 'none';
            }

            var select = e.target.closest('.custom-select > button');
            if (select) {
                var container = select.closest('.custom-select');
                var menu = container.querySelector('.dropdown-menu');
                var isOpen = !menu.classList.contains('hidden');
                closeAllDropdowns();
                if (!isOpen) {
                    var rect = select.getBoundingClientRect();
                    menu.style.left = rect.left + 'px';
                    menu.style.top = (rect.bottom + 4) + 'px';
                    menu.style.width = rect.width + 'px';
                    menu.classList.remove('hidden');
                    select.querySelector('.dropdown-chevron').style.transform = 'rotate(180deg)';
                }
                e.stopPropagation();
                return;
            }

            var option = e.target.closest('.dropdown-menu button[data-value]');
            if (option) {
                var container = option.closest('.custom-select');
                var value = option.getAttribute('data-value');
                container.querySelector('input[name="estatus"]').value = value;
                var btn = container.querySelector('button');
                var isCerrado = value === 'Cerrado';
                var darkSuffix = isCerrado ? 'dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-700' : 'dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700';
                btn.className = 'w-full flex items-center justify-between px-3 py-2.5 border-2 rounded-lg font-sans text-sm font-medium cursor-pointer transition-shadow ' + (isCerrado ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-amber-300 bg-amber-50 text-amber-700') + ' ' + darkSuffix;
                btn.innerHTML = '<span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full ' + (isCerrado ? 'bg-emerald-500' : 'bg-amber-500') + '"></span><span>' + value + '</span></span><svg class="dropdown-chevron transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
                container.querySelector('.dropdown-menu').classList.add('hidden');
                e.stopPropagation();
                return;
            }

            if (!e.target.closest('.custom-select')) {
                closeAllDropdowns();
            }
        });

        function closeAllDropdowns() {
            document.querySelectorAll('.custom-select .dropdown-menu').forEach(function(m) { m.classList.add('hidden'); });
            document.querySelectorAll('.custom-select .dropdown-chevron').forEach(function(c) { c.style.transform = 'rotate(0deg)'; });
        }
    </script>

</body>
</html>
