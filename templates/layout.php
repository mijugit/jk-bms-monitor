<?php
/**
 * Cache-busting helper: appends ?v=<filemtime> to a public asset path so browsers
 * pick up CSS/JS changes immediately after a deploy instead of serving a stale copy.
 */
$asset = static function (string $path): string {
    $full = dirname(__DIR__) . '/public' . $path;
    return is_file($full) ? $path . '?v=' . filemtime($full) : $path;
};
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JK BMS Monitor</title>
    <link rel="stylesheet" href="<?= $asset('/css/app.css') ?>">
</head>
<body>
<header class="site-header">
    <div class="container">
        <a class="site-logo" href="/">JK BMS Monitor</a>
        <?php if (!empty($_SESSION['authenticated'])): ?>
        <nav class="site-nav">
            <a href="/logout">Wyloguj</a>
        </nav>
        <?php endif; ?>
    </div>
</header>

<main class="site-main">
    <div class="container">
        <?php echo $content ?? ''; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="<?= $asset('/js/app.js') ?>"></script>
</body>
</html>
